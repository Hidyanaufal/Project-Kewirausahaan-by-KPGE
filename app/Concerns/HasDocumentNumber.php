<?php

namespace App\Concerns;

use Illuminate\Database\Eloquent\Model;

trait HasDocumentNumber
{
    public static function bootHasDocumentNumber()
    {
        static::creating(function (Model $model) {
            $column = $model->getNumberColumn();

            if (empty($model->{$column})) {
                $model->{$column} = $model::generateNumber($model);
            }
        });
    }

    public function getNumberColumn(): string
    {
        return 'number';
    }

    public function getDocumentPrefix(): string
    {
        return 'DOC';
    }

    public static function generateNumber(Model $model): string
    {
        $column = $model->getNumberColumn();
        $prefix = $model->getDocumentPrefix();
        $year   = now()->format('y');
        $basePrefix = $prefix . $year;

        $lastDocument = static::where($column, 'like', "{$basePrefix}-%")
            ->orderBy($column, 'desc')
            ->first();

        $lastCounter = $lastDocument
            ? (int) substr($lastDocument->{$column}, -3)
            : 0;

        $newCounter = $lastCounter + 1;

        return $basePrefix . '-' . str_pad($newCounter, 3, '0', STR_PAD_LEFT);
    }
}
