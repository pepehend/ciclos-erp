<?php
/**
 * Empresas (multiempresa). Quem cria e administra é o superadmin (users.role = 'superadmin',
 * sem company_id). Cada empresa nasce com os cadastros iniciais (formas e condições de
 * pagamento, status de orçamento, perfis de permissão e o cliente "Consumidor final").
 */
class Companies
{
    public const SLUG_MAX = 60;

    public static function slugify(string $name): string
    {
        $s = (string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        $s = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
        return substr($s !== '' ? $s : 'empresa', 0, self::SLUG_MAX);
    }

    public static function get(int $id): ?array
    {
        return DB::one('SELECT * FROM companies WHERE id = ?', [$id]);
    }

    public static function bySlug(string $slug): ?array
    {
        return DB::one('SELECT * FROM companies WHERE slug = ?', [strtolower(trim($slug))]);
    }

    /** Lista com contagem de usuários ativos (tela do superadmin). */
    public static function list(): array
    {
        $rows = DB::all('SELECT c.*,
            (SELECT COUNT(*) FROM users u WHERE u.company_id = c.id AND u.active = 1) AS users
            FROM companies c ORDER BY c.name');
        foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['active'] = (int)$r['active']; $r['users'] = (int)$r['users']; }
        return $rows;
    }

    /**
     * Cria a empresa, os cadastros iniciais e (se vier `admin`) o administrador dela. Tudo numa transação.
     * `admin` = [name, email, password]. Devolve o id da empresa.
     */
    public static function create(array $d): int
    {
        $name = trim((string)($d['name'] ?? ''));
        if ($name === '') throw new AppError('Informe o nome da empresa.');
        if (mb_strlen($name) > 160) throw new AppError('O nome da empresa deve ter até 160 caracteres.');
        $doc = preg_replace('/\D/', '', (string)($d['document'] ?? ''));
        if ($doc !== '' && !in_array(strlen($doc), [11, 14], true)) throw new AppError('CPF/CNPJ da empresa inválido.');
        $slug = self::slugify((string)(($d['slug'] ?? '') !== '' ? $d['slug'] : $name));
        if (self::bySlug($slug)) throw new AppError('Já existe uma empresa com o código "' . $slug . '".');
        $admin = $d['admin'] ?? null;
        if ($admin) {
            // Confere tudo antes de criar qualquer coisa: o e-mail é o login e só pode existir uma vez no sistema inteiro
            $email = mb_strtolower(trim((string)($admin['email'] ?? '')));
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new AppError('Informe um e-mail válido para o administrador da empresa.');
            if (DB::val('SELECT id FROM users WHERE email = ?', [$email])) {
                throw new AppError('O e-mail ' . $email . ' já está cadastrado no sistema (cada e-mail só pode ser de um usuário, de uma única empresa). Use outro e-mail para o administrador.');
            }
            if (trim((string)($admin['name'] ?? '')) === '') throw new AppError('Informe o nome do administrador da empresa.');
            Auth::checkPassword((string)($admin['password'] ?? ''));
        }

        return DB::tx(function () use ($name, $doc, $slug, $admin) {
            $id = DB::insert('companies', ['name' => $name, 'slug' => $slug, 'document' => $doc !== '' ? $doc : null,
                'active' => 1, 'created_at' => now()]);
            Tenant::with($id, function () use ($name, $doc, $admin) {
                self::seed($name, $doc);
                if ($admin) {
                    Auth::createUser(['name' => $admin['name'] ?? '', 'email' => $admin['email'] ?? '',
                        'password' => $admin['password'] ?? '', 'role' => 'admin']);
                }
            });
            return $id;
        });
    }

    /** Cadastros iniciais da empresa atual (Tenant). */
    public static function seed(string $name = '', string $doc = ''): void
    {
        PaymentMethods::seed();
        PaymentTerms::seed();
        QuoteStatuses::seed();
        Auth::seedProfiles();
        Customers::ensureDefault();
        if (!(int)DB::val('SELECT COUNT(*) FROM task_types WHERE company_id = ?', [Tenant::need()])) TaskTypes::importLegacy();   // tipos de tarefa padrão
        if ($name !== '') Settings::save(['company_name' => $name, 'company_document' => $doc]);
    }

    public static function update(int $id, array $d): void
    {
        $c = self::get($id);
        if (!$c) throw new AppError('Empresa não encontrada.', 404);
        $name = trim((string)($d['name'] ?? $c['name']));
        if ($name === '') throw new AppError('Informe o nome da empresa.');
        $doc = preg_replace('/\D/', '', (string)($d['document'] ?? $c['document'] ?? ''));
        if ($doc !== '' && !in_array(strlen($doc), [11, 14], true)) throw new AppError('CPF/CNPJ da empresa inválido.');
        $slug = isset($d['slug']) && $d['slug'] !== '' ? self::slugify((string)$d['slug']) : $c['slug'];
        $other = self::bySlug($slug);
        if ($other && (int)$other['id'] !== $id) throw new AppError('Já existe uma empresa com o código "' . $slug . '".');
        DB::update('companies', $id, ['name' => $name, 'slug' => $slug, 'document' => $doc !== '' ? $doc : null]);
    }

    /** Ativa ou desativa. Empresa desativada não entra (web, app e portal); os tokens do app dela caem. */
    public static function setActive(int $id, bool $active): void
    {
        if (!self::get($id)) throw new AppError('Empresa não encontrada.', 404);
        DB::update('companies', $id, ['active' => $active ? 1 : 0]);
        if (!$active) {
            DB::run('DELETE FROM api_tokens WHERE user_id IN (SELECT id FROM users WHERE company_id = ?)', [$id]);
        }
    }
}
