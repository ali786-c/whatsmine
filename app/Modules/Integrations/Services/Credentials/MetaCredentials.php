<?php

namespace App\Modules\Integrations\Services\Credentials;

class MetaCredentials extends CredentialValueObject
{
    public function appId(): ?string
    {
        $val = $this->get('app_id');
        return $val !== null ? trim((string) $val) : null;
    }

    public function appSecret(): ?string
    {
        $val = $this->get('app_secret');
        if ($val === null) {
            return null;
        }
        $str = trim((string) $val);
        if (preg_match('/[\x{2022}•]/u', $str)) {
            return null; // Safety guard: never return masked bullet placeholder as actual secret
        }
        return $str !== '' ? $str : null;
    }

    public function systemUserToken(): ?string
    {
        $val = $this->get('system_user_token');
        return $val !== null ? trim((string) $val) : null;
    }

    public function verifyToken(): ?string
    {
        $val = $this->get('verify_token');
        return $val !== null ? trim((string) $val) : null;
    }

    public function configIdWhatsapp(): ?string
    {
        $val = $this->get('config_id_whatsapp');
        return $val !== null && trim((string) $val) !== '' ? trim((string) $val) : null;
    }

    public function configIdSocial(): ?string
    {
        $val = $this->get('config_id_social');
        return $val !== null && trim((string) $val) !== '' ? trim((string) $val) : null;
    }
}
