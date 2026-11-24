<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Request;
use App\Services\AuthService;
use App\Services\PrivateFileService;

/** Streams private files after an access check. Unknown or forbidden files are both 404. */
final class FileController extends Controller
{
    public function task(Request $request, string $id): void
    {
        if (!ctype_digit($id)) {
            $this->notFound();
        }
        $file = PrivateFileService::findTaskAttachment((array) AuthService::user(), (int) $id);
        PrivateFileService::stream(PrivateFileService::taskDir(), (string) $file['file_name']);
    }
}
