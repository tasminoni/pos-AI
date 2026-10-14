<?php
declare(strict_types=1);

/** Scope comes from server configuration or a POS session, never request/model JSON. */
final class ReportScope
{
    private function __construct(
        public readonly string $userId,
        public readonly int $zid,
        public readonly array $shopnos,
        public readonly string $schemaId = ''
    ) {}

    public static function forAccess(array $config): self
    {
        require_once __DIR__.'/SqlSchema.php';
        $schema=SqlSchema::load($config['sql_schema_path']??__DIR__.'/../sql_schema.json');
        $schemaId=hash('sha256',json_encode([$schema,$config['db']['server']??'',$config['db']['database']??''],JSON_THROW_ON_ERROR));
        return self::forSchema($schema,$config,$schemaId);
    }

    public static function forSchema(array $schema,array $config,string $schemaId=''): self
    {
        $rules=$schema['access']['row_scope']??[];
        $tenant=$rules['tenant']['value']??0;
        $shops=array_map('strval',$rules['shop']['values']??[]);
        if (($config['chat_require_session']??true)!==false) {
            $session=self::fromSession();
            return new self($session->userId,$session->zid,$session->shopnos,$schemaId);
        }
        return new self('public-reporting',is_int($tenant)?$tenant:0,$shops,$schemaId);
    }

    public static function fromSession(): self
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            throw new RuntimeException('An authenticated PHP session is required.');
        }
        // This adapter must be populated by the host POS login integration.
        $identity = $_SESSION['reporting_identity'] ?? null;
        if (!is_array($identity) || ($identity['authenticated'] ?? false) !== true) {
            throw new RuntimeException('Reporting identity is not authenticated.');
        }
        $userId = $identity['user_id'] ?? null;
        $zid = $identity['zid'] ?? null;
        $shops = $identity['allowed_shopnos'] ?? null;
        if (!is_string($userId) || $userId === '' || !is_int($zid) || $zid <= 0 ||
            !is_array($shops) || !$shops || count($shops) > 50) {
            throw new RuntimeException('Reporting scope is missing or invalid.');
        }
        $validatedShops = [];
        foreach ($shops as $shop) {
            if (!is_string($shop) || $shop === '' || strlen($shop) > 64) {
                throw new RuntimeException('Reporting shop scope is invalid.');
            }
            $validatedShops[] = $shop;
        }
        return new self($userId, $zid, array_values(array_unique($validatedShops)));
    }

    /** Narrow an authenticated scope; a request can never add a shop. */
    public function forShop(string $shopno): self
    {
        if (!in_array($shopno, $this->shopnos, true)) {
            throw new InvalidArgumentException('Shop is outside the authenticated reporting scope.');
        }
        return new self($this->userId, $this->zid, [$shopno],$this->schemaId);
    }
}
