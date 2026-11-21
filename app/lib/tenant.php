<?php
/**
 * Empresa atual (multiempresa). Todos os dados do sistema ficam num banco só e cada linha
 * guarda o `company_id` da empresa dona. A empresa vem do usuário logado (Auth::load), do
 * registro achado por token nas páginas públicas, da sessão do portal ou do laço do cron.
 *
 * Regra: tabela nova que guarde dados de empresa leva `company_id` e entra em Tenant::TABLES.
 */
class Tenant
{
    /** Tabelas com dados de empresa (`company_id NOT NULL`). `DB::insert/update` escopam por elas. */
    public const TABLES = [
        'permission_profiles', 'products', 'recipe_items', 'customers', 'suppliers', 'sales', 'sale_items',
        'invoices', 'invoice_items', 'supplier_map', 'product_refs', 'customer_portal', 'portal_requests',
        'settings', 'quotes', 'quote_items', 'quote_extras', 'financial_entries', 'equipments',
        'checklist_models', 'questionnaires', 'task_types', 'service_orders', 'so_answers', 'so_photos',
        'so_items', 'so_events', 'so_sends', 'purchase_orders', 'purchase_order_items', 'payment_methods',
        'payment_terms', 'quote_statuses', 'movements',
    ];

    private static ?int $id = null;

    /** Id da empresa atual (null = nenhuma, como o superadmin ou antes do login). */
    public static function id(): ?int { return self::$id; }

    public static function set(?int $id): void
    {
        if (self::$id === $id) return;
        self::$id = $id;
        self::resetCaches();
    }

    public static function clear(): void { self::set(null); }

    /** Empresa atual ou erro 403: toda rota de dados da empresa chama isto (direta ou indiretamente). */
    public static function need(): int
    {
        if (self::$id === null) throw new AppError('Nenhuma empresa selecionada.', 403);
        return self::$id;
    }

    /** A tabela guarda dados de empresa? */
    public static function scoped(string $table): bool
    {
        static $map = null;
        $map ??= array_flip(self::TABLES);
        return isset($map[$table]);
    }

    /** Roda $fn na empresa $id e volta para a anterior (cron, páginas públicas, testes). */
    public static function with(?int $id, callable $fn)
    {
        $prev = self::$id;
        self::set($id);
        try { return $fn(); }
        finally { self::set($prev); }
    }

    /** Empresas ativas (para o cron e para o portal). */
    public static function activeIds(): array
    {
        return array_map('intval', array_column(DB::all('SELECT id FROM companies WHERE active = 1 ORDER BY id'), 'id'));
    }

    /** Dados guardados na memória por empresa: zerados ao trocar de empresa. */
    private static function resetCaches(): void
    {
        if (class_exists('PaymentMethods', false)) PaymentMethods::reset();
        if (class_exists('Settings', false) && method_exists('Settings', 'reset')) Settings::reset();
        if (class_exists('QuoteStatuses', false)) QuoteStatuses::reset();
        if (class_exists('Auth', false) && method_exists('Auth', 'resetCache')) Auth::resetCache();
    }
}
