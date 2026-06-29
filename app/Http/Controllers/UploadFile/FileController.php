<?php

namespace App\Http\Controllers\UploadFile;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class FileController extends Controller
{
    public function store(Request $request)
    {
        $paths = [];

        foreach ($request->allFiles() as $file) {
            if ($file instanceof UploadedFile) {
                $paths[] = $this->uploadFile($file);
            } else {
                // Если пришел массив файлов (мультизагрузка)
                foreach ($file as $f) {
                    if ($f instanceof UploadedFile) {
                        $paths[] = $this->uploadFile($f);
                    }
                }
            }
        }

        return response()->json($paths);
    }

    /**
     * Хелпер для сохранения файла с его оригинальным расширением
     */
    private function uploadFile(UploadedFile $file): string
    {
        // Генерируем уникальное имя, но берем родное расширение (например, csv)
        $extension = $file->getClientOriginalExtension();
        $filename = Str::random(40) . '.' . $extension;

        // Используем storeAs вместо store
        return $file->storeAs('uploads', $filename, 'company');
    }
}
