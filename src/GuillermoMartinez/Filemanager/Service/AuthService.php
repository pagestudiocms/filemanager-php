<?php
namespace GuillermoMartinez\Filemanager\Service;

class AuthService
{
    private array $tokens = [];

    public function __construct(array $config = [])
    {
        if (isset($config['tokens']) && is_array($config['tokens'])) {
            $this->tokens = $config['tokens'];
        }
    }

    /**
     * Simple token check. Returns true when token present in configured list.
     */
    public function authorize(?string $token): bool
    {
        if ($token === null || $token === '') return false;
        return in_array($token, $this->tokens, true);
    }
}
