<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;

class HashService
{
    public function hashSha256(UploadedFile $file): string
    {
        $path = $file->getRealPath();
        if (!$path || !is_file($path)) {
            throw new \RuntimeException('Fichier temporaire introuvable pour le calcul du hash.');
        }

        $ctx    = hash_init('sha256');
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Impossible d\'ouvrir le fichier pour calculer le hash.');
        }

        try {
            while (!feof($handle)) {
                $chunk = fread($handle, 1024 * 1024);
                if ($chunk === false) break;
                hash_update($ctx, $chunk);
            }
        } finally {
            fclose($handle);
        }

        return hash_final($ctx);
    }
}
