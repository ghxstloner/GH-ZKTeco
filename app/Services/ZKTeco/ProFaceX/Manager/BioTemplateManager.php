<?php

namespace App\Services\ZKTeco\ProFaceX\Manager;

class BioTemplateManager
{
    public function createBioTemplate($personBioTemplateList = [])
    {
        foreach ($personBioTemplateList as $personBioTemplate) {
            $personBioTemplate->save();
        }
    }
}
