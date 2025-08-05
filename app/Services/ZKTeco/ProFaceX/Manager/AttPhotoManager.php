<?php

namespace App\Services\ZKTeco\ProFaceX\Manager;

class AttPhotoManager
{
    public static function createAttPhoto(array $attPhotoList = []): void
    {
        foreach ($attPhotoList as $attPhoto) {
            $attPhoto->save();
        }
    }
}
