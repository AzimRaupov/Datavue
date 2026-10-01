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

                foreach ($file as $f) {
                    if ($f instanceof UploadedFile) {
                        $paths[] = $this->uploadFile($f);
                    }
                }
            }
        }

        return response()->json($paths);
    }

    private function uploadFile(UploadedFile $file): string
    {

        $extension = $file->getClientOriginalExtension();
        $filename = Str::random(40) . '.' . $extension;

        return $file->storeAs('uploads', $filename, 'company');
    }
}
