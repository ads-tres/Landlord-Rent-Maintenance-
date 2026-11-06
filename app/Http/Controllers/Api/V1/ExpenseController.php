<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\ExpenseResource;
use App\Models\Expense;
use App\Models\Property;
use App\Models\Unit;
use App\Services\Repairs\PhotoStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Money spent on properties. Amounts in santim. */
class ExpenseController extends Controller
{
    /** GET /expenses?property_id=&category=&from=&to= — newest first, with the filtered total. */
    public function index(Request $request): AnonymousResourceCollection
    {
        $filters = $request->validate([
            'property_id' => ['sometimes', 'integer'],
            'category' => ['sometimes', Rule::in(Expense::CATEGORIES)],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d'],
        ]);

        $query = Expense::query()
            ->when($filters['property_id'] ?? null, fn ($q, $id) => $q->where('property_id', $id))
            ->when($filters['category'] ?? null, fn ($q, $c) => $q->where('category', $c))
            ->when($filters['from'] ?? null, fn ($q, $d) => $q->whereDate('spent_on', '>=', $d))
            ->when($filters['to'] ?? null, fn ($q, $d) => $q->whereDate('spent_on', '<=', $d));

        $total = (int) (clone $query)->sum('amount');

        return ExpenseResource::collection(
            $query->with(['property:id,name', 'unit:id,label', 'attachments'])
                ->orderByDesc('spent_on')->orderByDesc('id')
                ->limit(500)->get()
        )->additional(['meta' => ['total' => $total]]);
    }

    /** POST /expenses — multipart when a receipt photo is attached. */
    public function store(Request $request, PhotoStore $photos): ExpenseResource|JsonResponse
    {
        // An expense logged offline and sent again: return the one already saved.
        if ($existing = $this->alreadySaved(Expense::class, $request)) {
            return (new ExpenseResource($this->loadDetail($existing)))->response()->setStatusCode(200);
        }

        $data = $this->validated($request, true);
        $property = Property::query()->findOrFail($data['property_id']);
        Gate::authorize('update', $property);
        $this->checkUnit($data['unit_id'] ?? null, $property->id);

        $expense = DB::transaction(function () use ($data, $property, $request, $photos) {
            $expense = new Expense($data);
            $expense->landlord_id = $property->landlord_id;
            $expense->save();
            if ($request->hasFile('photo')) {
                $photos->store($request->file('photo'), $expense, $expense->landlord_id);
            }

            return $expense;
        });

        return new ExpenseResource($this->loadDetail($expense));
    }

    public function show(Expense $expense): ExpenseResource
    {
        Gate::authorize('view', $expense);

        return new ExpenseResource($this->loadDetail($expense));
    }

    public function update(Request $request, Expense $expense): ExpenseResource
    {
        Gate::authorize('update', $expense);
        $this->guardManual($expense);
        $data = $this->validated($request, false);

        if (isset($data['property_id'])) {
            Gate::authorize('update', Property::query()->findOrFail($data['property_id']));
        }
        if (array_key_exists('unit_id', $data)) {
            $this->checkUnit($data['unit_id'], $data['property_id'] ?? $expense->property_id);
        }
        $expense->update($data);

        return new ExpenseResource($this->loadDetail($expense));
    }

    public function destroy(Expense $expense): Response
    {
        Gate::authorize('delete', $expense);
        $this->guardManual($expense);
        $expense->attachments->each->delete();
        $expense->delete();

        return response()->noContent();
    }

    /** POST /expenses/{id}/photos — a receipt photo; automatic expenses can have one too. */
    public function addPhoto(Request $request, Expense $expense, PhotoStore $photos): ExpenseResource
    {
        Gate::authorize('update', $expense);
        $request->validate(['photo' => ['required', 'image', 'mimes:jpeg,jpg,png,webp,heic', 'max:8192']]);
        abort_if($expense->attachments()->count() >= 4, 422, __('expenses.too_many_photos'));
        $photos->store($request->file('photo'), $expense, $expense->landlord_id);

        return new ExpenseResource($this->loadDetail($expense));
    }

    private function validated(Request $request, bool $creating): array
    {
        $req = $creating ? 'required' : 'sometimes';

        return $request->validate([
            'property_id' => [$req, 'integer'],
            'unit_id' => ['sometimes', 'nullable', 'integer'],
            'category' => [$req, Rule::in(Expense::CATEGORIES)],
            'amount' => [$req, 'integer', 'min:1', 'max:10000000000'],
            'spent_on' => [$req, 'date_format:Y-m-d', 'before_or_equal:today'],
            'description' => ['sometimes', 'nullable', 'string', 'max:250'],
            'vendor' => ['sometimes', 'nullable', 'string', 'max:120'],
            'client_ref' => ['sometimes', 'nullable', 'string', 'max:64'],
            'photo' => ['sometimes', 'image', 'mimes:jpeg,jpg,png,webp,heic', 'max:8192'],
        ]);
    }

    private function checkUnit(?int $unitId, int $propertyId): void
    {
        if ($unitId !== null && ! Unit::query()->whereKey($unitId)->where('property_id', $propertyId)->exists()) {
            throw ValidationException::withMessages(['unit_id' => __('expenses.unit_not_in_property')]);
        }
    }

    private function guardManual(Expense $expense): void
    {
        if ($expense->isAutomatic()) {
            throw ValidationException::withMessages(['expense' => __('expenses.automatic')]);
        }
    }

    private function loadDetail(Expense $expense): Expense
    {
        return $expense->load(['property:id,name', 'unit:id,label', 'attachments']);
    }
}
