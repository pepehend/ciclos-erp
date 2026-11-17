<?php
/**
 * Exporta todos os dados em um arquivo .sql (INSERTs), para baixar e guardar.
 */

class Backup
{
    const TABLES = ['permission_profiles', 'users', 'customers', 'suppliers', 'products', 'recipe_items', 'sales', 'sale_items', 'invoices',
        'invoice_items', 'supplier_map', 'movements', 'settings', 'quotes', 'quote_items', 'quote_extras', 'purchase_orders', 'purchase_order_items', 'financial_entries', 'payment_methods', 'payment_terms', 'quote_statuses', 'product_refs', 'customer_portal', 'portal_requests'];

    public static function sql(): string
    {
        $pdo = DB::pdo();
        $out = "-- Backup do Sistema de Gestão de Serviços - " . date('d/m/Y H:i:s') . "\n";
        $out .= "-- Para restaurar: crie as tabelas pelo install.php e importe este arquivo no phpMyAdmin.\n";
        $out .= "SET FOREIGN_KEY_CHECKS=0;\n\n";
        foreach (self::TABLES as $t) {
            // A conexão com o Google Drive (tokens e chave secreta) não vai no arquivo e não é apagada ao restaurar
            $only = $t === 'settings' ? " WHERE SUBSTR(name, 1, 7) <> 'gdrive_'" : '';
            $out .= "DELETE FROM `$t`$only;\n";
            $rows = DB::all("SELECT * FROM $t$only ORDER BY id");
            foreach (array_chunk($rows, 200) as $chunk) {
                $cols = '`' . implode('`,`', array_keys($chunk[0])) . '`';
                $vals = [];
                foreach ($chunk as $r) {
                    $vals[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $r)) . ')';
                }
                $out .= "INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $vals) . ";\n";
            }
            $out .= "\n";
        }
        return $out . "SET FOREIGN_KEY_CHECKS=1;\n";
    }
}
