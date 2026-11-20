<?php
/**
 * Exclusão de cadastros: clientes, fornecedores, equipamentos, tipos de tarefa e questionários.
 * Nunca usado → apaga de vez. Já usado → marca deleted_at (e active = 0): some das listas e das escolhas,
 * mas continua aparecendo nos orçamentos, vendas, OS, notas, ordens de compra e lançamentos onde já estava.
 */
class Removals
{
    /** Onde cada cadastro pode ter sido usado: [tabela, coluna]. */
    const REFS = [
        'customers' => [['sales', 'customer_id'], ['quotes', 'customer_id'], ['service_orders', 'customer_id'],
            ['equipments', 'customer_id'], ['financial_entries', 'customer_id']],
        'suppliers' => [['invoices', 'supplier_id'], ['purchase_orders', 'supplier_id'], ['financial_entries', 'supplier_id']],
        'equipments' => [['service_orders', 'equipment_id'], ['so_answers', 'equipment_id']],
        'task_types' => [['service_orders', 'task_type_id']],
        'questionnaires' => [['so_answers', 'questionnaire_id']],
    ];
    const LABELS = [
        'customers' => 'Cliente', 'suppliers' => 'Fornecedor', 'equipments' => 'Equipamento',
        'task_types' => 'Tipo de tarefa', 'questionnaires' => 'Questionário',
    ];

    public static function used(string $table, int $id): bool
    {
        foreach (self::REFS[$table] as [$t, $col]) {
            if (DB::val("SELECT 1 FROM $t WHERE $col = ? LIMIT 1", [$id])) return true;
        }
        return false;
    }

    /** Exclui; devolve 'apagado' (nunca usado) ou 'excluido' (usado: fica guardado para o histórico). */
    public static function delete(string $table, int $id): string
    {
        if (!isset(self::REFS[$table])) throw new AppError('Cadastro inválido');
        $row = DB::one("SELECT * FROM $table WHERE id = ?", [$id]);
        if (!$row || !empty($row['deleted_at'])) throw new AppError(self::LABELS[$table] . ' não encontrado', 404);
        if ($table === 'customers' && $id === Customers::ensureDefault()) {
            throw new AppError('O cliente "' . Customers::DEFAULT_NAME . '" é usado nas vendas de balcão e não pode ser excluído');
        }
        return DB::tx(function () use ($table, $id) {
            if ($table === 'customers') {
                // Cliente excluído perde o acesso ao portal
                DB::run('DELETE FROM customer_portal WHERE customer_id = ?', [$id]);
            }
            if ($table === 'questionnaires') {
                // Sai dos tipos de tarefa; as OS que já responderam continuam mostrando as respostas
                foreach (DB::all('SELECT id, questionnaire_ids FROM task_types') as $t) {
                    $ids = json_decode((string)$t['questionnaire_ids'], true) ?: [];
                    $keep = array_values(array_filter($ids, fn($q) => (int)$q !== $id));
                    if (count($keep) !== count($ids)) DB::update('task_types', (int)$t['id'], ['questionnaire_ids' => json_encode($keep), 'updated_at' => now()]);
                }
            }
            if (!self::used($table, $id)) {
                if ($table === 'customers') DB::run('DELETE FROM portal_requests WHERE customer_id = ?', [$id]);
                DB::run("DELETE FROM $table WHERE id = ?", [$id]);
                return 'apagado';
            }
            DB::update($table, $id, ['deleted_at' => now(), 'active' => 0, 'updated_at' => now()]);
            return 'excluido';
        });
    }

    /**
     * Confere a escolha de um cadastro num registro: excluído só vale se o registro já usava esse mesmo item
     * (o orçamento do cliente X continua com X até ser trocado).
     */
    public static function check(string $table, ?int $id, ?int $current = null): void
    {
        if (!$id || $id === $current) return;
        $del = DB::val("SELECT deleted_at FROM $table WHERE id = ?", [$id]);
        if ($del) throw new AppError(self::LABELS[$table] . ' excluído. Escolha outro.');
    }
}
