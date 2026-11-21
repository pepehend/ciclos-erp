<?php
/**
 * Exporta todos os dados em um arquivo .sql (INSERTs), para baixar e guardar.
 */

class Backup
{
    const TABLES = ['permission_profiles', 'users', 'customers', 'suppliers', 'products', 'recipe_items', 'sales', 'sale_items', 'invoices',
        'invoice_items', 'supplier_map', 'movements', 'settings', 'quotes', 'quote_items', 'quote_extras', 'purchase_orders', 'purchase_order_items', 'equipments', 'checklist_models', 'questionnaires', 'task_types', 'service_orders', 'so_answers', 'so_photos', 'so_items', 'so_events', 'so_sends', 'financial_entries', 'payment_methods', 'payment_terms', 'quote_statuses', 'product_refs', 'customer_portal', 'portal_requests'];

    /**
     * Dump em SQL. Padrão: só a empresa atual (DELETE e INSERT filtrados pelo company_id dela). Com $all (superadmin ou
     * rotina do servidor), o banco inteiro, com as empresas. Restaurar só uma empresa num banco que tem outras pode colidir ids:
     * para isso vale o dump do banco inteiro.
     */
    public static function sql(bool $all = false): string
    {
        $pdo = DB::pdo();
        $cid = $all ? null : Tenant::need();
        $out = "-- Backup do CiclosERP - " . date('d/m/Y H:i:s') . ($cid ? " - empresa #$cid" : ' - todas as empresas') . "
";
        $out .= "-- Para restaurar: crie as tabelas pelo install.php e importe este arquivo no phpMyAdmin.
";
        $out .= "SET FOREIGN_KEY_CHECKS=0;

";
        $tables = $all ? ['companies', ...self::TABLES] : self::TABLES;
        foreach ($tables as $t) {
            // A conexão com o Google Drive (tokens e chave secreta) não vai no arquivo e não é apagada ao restaurar
            $w = [];
            if ($t === 'settings') $w[] = "SUBSTR(name, 1, 7) <> 'gdrive_'";
            if ($cid !== null) $w[] = "company_id = $cid";
            $only = $w ? ' WHERE ' . implode(' AND ', $w) : '';
            $out .= "DELETE FROM `$t`$only;
";
            $rows = DB::all("SELECT * FROM $t$only ORDER BY id");
            foreach (array_chunk($rows, 200) as $chunk) {
                $cols = '`' . implode('`,`', array_keys($chunk[0])) . '`';
                $vals = [];
                foreach ($chunk as $r) {
                    $vals[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $r)) . ')';
                }
                $out .= "INSERT INTO `$t` ($cols) VALUES
" . implode(",
", $vals) . ";
";
            }
            $out .= "
";
        }
        return $out . "SET FOREIGN_KEY_CHECKS=1;
";
    }
}
