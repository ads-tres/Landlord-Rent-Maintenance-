<?php

namespace App\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * The record already saved with the request's client_ref (sent again after an offline save),
     * within the landlord's own records.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $model
     * @return T|null
     */
    protected function alreadySaved(string $model, Request $request): ?Model
    {
        $ref = $request->input('client_ref');

        return is_string($ref) && $ref !== ''
            ? $model::query()->where('client_ref', $ref)->first()
            : null;
    }
}
