<?php
/**
 * Conexão com o banco e criação das tabelas.
 * Produção: MySQL/MariaDB. Testes/uso local: SQLite.
 */

class DB
{
    private static ?PDO $pdo = null;
    private static string $driver = 'mysql';
    private static int $txDepth = 0;

    public static function connect(array $cfg): PDO
    {
        self::$driver = $cfg['driver'] ?? 'mysql';
        if (class_exists('PaymentMethods', false)) PaymentMethods::reset();
        if (self::$driver === 'sqlite') {
            $pdo = new PDO('sqlite:' . $cfg['path']);
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA journal_mode = WAL');
        } else {
            $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $cfg['host'], (int)($cfg['port'] ?? 3306), $cfg['name']);
            $pdo = new PDO($dsn, $cfg['user'], $cfg['pass'], [
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4, time_zone = '-03:00'",
            ]);
        }
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        self::$pdo = $pdo;
        return $pdo;
    }

    public static function pdo(): PDO
    {
        if (!self::$pdo) throw new RuntimeException('Banco não conectado');
        return self::$pdo;
    }

    public static function driver(): string { return self::$driver; }

    public static function all(string $sql, array $p = []): array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($p);
        return $st->fetchAll();
    }

    public static function one(string $sql, array $p = []): ?array
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($p);
        $r = $st->fetch();
        return $r === false ? null : $r;
    }

    public static function val(string $sql, array $p = [])
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($p);
        $r = $st->fetchColumn();
        return $r === false ? null : $r;
    }

    public static function run(string $sql, array $p = []): int
    {
        $st = self::pdo()->prepare($sql);
        $st->execute($p);
        return $st->rowCount();
    }

    /** Rodando DB::upgrade(): ainda não há empresa; os dados antigos ganham a empresa no fim da migração. */
    private static bool $upgrading = false;

    public static function insert(string $table, array $data): int
    {
        if (Tenant::scoped($table) && !array_key_exists('company_id', $data)) {
            $cid = Tenant::id();
            if ($cid === null) {
                if (!self::$upgrading) throw new AppError('Nenhuma empresa selecionada.', 403);
                $cid = 0;
            }
            $data['company_id'] = $cid;
        } elseif ($table === 'users' && !array_key_exists('company_id', $data) && Tenant::id() !== null) {
            $data['company_id'] = Tenant::id();
        }
        $cols = array_keys($data);
        $sql = sprintf('INSERT INTO %s (%s) VALUES (%s)', $table,
            implode(',', $cols), implode(',', array_fill(0, count($cols), '?')));
        self::run($sql, array_values($data));
        return (int)self::pdo()->lastInsertId();
    }

    /** Tabelas com número próprio por empresa (coluna `number`; o `id` continua global). */
    public const NUMBERED = ['service_orders', 'quotes', 'sales', 'purchase_orders'];

    /**
     * Insere numerando: o número é o próximo da empresa atual (MAX+1). A chave única (empresa, número)
     * barra corrida entre dois pedidos ao mesmo tempo; nesse caso tenta de novo.
     */
    public static function insertNumbered(string $table, array $data): int
    {
        for ($try = 0; ; $try++) {
            try {
                return self::tx(function () use ($table, $data) {
                    $next = (int)self::val("SELECT COALESCE(MAX(number), 0) + 1 FROM $table WHERE company_id = ?", [Tenant::need()]);
                    return self::insert($table, $data + ['number' => $next]);
                });
            } catch (PDOException $e) {
                if ($try >= 3) throw $e;
            }
        }
    }

    /** Número (por empresa) mostrado ao usuário para o id de um registro numerado. */
    public static function numberOf(string $table, ?int $id): ?int
    {
        if (!$id) return null;
        $n = self::val("SELECT number FROM $table WHERE id = ? AND company_id = ?", [$id, Tenant::need()]);
        return $n === null ? null : (int)$n;
    }

    /** Grava por id. Em tabela de empresa só mexe em linha da empresa atual (outra empresa = 0 linhas). */
    public static function update(string $table, int $id, array $data): void
    {
        $sets = implode(',', array_map(fn($c) => "$c = ?", array_keys($data)));
        $p = [...array_values($data), $id];
        $where = 'id = ?';
        if ((Tenant::scoped($table) || $table === 'users') && !self::$upgrading) {
            $cid = Tenant::id();
            if ($cid !== null) { $where .= ' AND company_id = ?'; $p[] = $cid; }
            elseif ($table !== 'users') throw new AppError('Nenhuma empresa selecionada.', 403);
        }
        self::run("UPDATE $table SET $sets WHERE $where", $p);
    }

    /** Transação com suporte a aninhamento (só a mais externa faz COMMIT). */
    public static function tx(callable $fn)
    {
        $pdo = self::pdo();
        if (self::$txDepth === 0) $pdo->beginTransaction();
        self::$txDepth++;
        try {
            $r = $fn();
            self::$txDepth--;
            if (self::$txDepth === 0) $pdo->commit();
            return $r;
        } catch (Throwable $e) {
            self::$txDepth--;
            if (self::$txDepth === 0 && $pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }

    /** Trava a linha do produto durante a transação (MySQL). */
    public static function lockSuffix(): string
    {
        return self::$driver === 'mysql' ? ' FOR UPDATE' : '';
    }

    public static function schema(): array
    {
        $my = self::$driver === 'mysql';
        $pk = $my ? 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        $fk = $my ? 'INT UNSIGNED' : 'INTEGER';
        $end = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci' : '';
        $qty = 'DECIMAL(16,4)';
        $money = 'DECIMAL(14,2)';
        $cost = 'DECIMAL(18,6)';
        $dt = $my ? 'DATETIME' : 'TEXT';
        $big = $my ? 'MEDIUMTEXT' : 'TEXT';

        $list = [
            "CREATE TABLE IF NOT EXISTS companies (
                id $pk,
                name VARCHAR(160) NOT NULL,
                slug VARCHAR(60) NOT NULL UNIQUE,
                document VARCHAR(14) NULL,
                active TINYINT NOT NULL DEFAULT 1,
                created_at $dt NOT NULL
            )$end",
            "CREATE TABLE IF NOT EXISTS users (
                id $pk,
                company_id $fk NULL,
                name VARCHAR(120) NOT NULL,
                email VARCHAR(190) NOT NULL UNIQUE,
                phone VARCHAR(30) NULL,
                accent_color VARCHAR(7) NULL,
                avatar $big NULL,
                password_hash VARCHAR(255) NOT NULL,
                role VARCHAR(20) NOT NULL DEFAULT 'membro',
                permissions TEXT NULL,
                profile_id $fk NULL,
                active TINYINT NOT NULL DEFAULT 1,
                created_at $dt NOT NULL
            )$end",
            "CREATE TABLE IF NOT EXISTS permission_profiles (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                name VARCHAR(80) NOT NULL,
                permissions TEXT NULL,
                created_at $dt NOT NULL
            )$end",
            "CREATE TABLE IF NOT EXISTS login_attempts (
                id $pk,
                ip VARCHAR(64) NOT NULL,
                created_at $dt NOT NULL
            )$end",
            // Aparelhos com acesso à API por token (aplicativo). Só o SHA-256 do token fica no banco.
            "CREATE TABLE IF NOT EXISTS api_tokens (
                id $pk,
                user_id $fk NOT NULL,
                token_hash CHAR(64) NOT NULL UNIQUE,
                device VARCHAR(120) NOT NULL,
                ip VARCHAR(64) NULL,
                created_at $dt NOT NULL,
                last_used_at $dt NOT NULL
            )$end",
            "CREATE INDEX idx_api_tokens_user ON api_tokens (user_id)",
            "CREATE TABLE IF NOT EXISTS products (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                name VARCHAR(200) NOT NULL,
                sku VARCHAR(60) NULL,
                barcode VARCHAR(60) NULL,
                category VARCHAR(80) NULL,
                location VARCHAR(80) NULL,
                brand VARCHAR(80) NULL,
                mfr_code VARCHAR(80) NULL,
                description TEXT NULL,
                fiscal TEXT NULL,
                type VARCHAR(20) NOT NULL DEFAULT 'produto',
                unit VARCHAR(10) NOT NULL DEFAULT 'un',
                stock $qty NOT NULL DEFAULT 0,
                avg_cost $cost NOT NULL DEFAULT 0,
                sale_price $money NOT NULL DEFAULT 0,
                markup DECIMAL(8,2) NULL,
                min_stock $qty NOT NULL DEFAULT 0,
                recipe_yield $qty NOT NULL DEFAULT 1,
                recipe_mode VARCHAR(20) NOT NULL DEFAULT 'estoque',
                active TINYINT NOT NULL DEFAULT 1,
                deleted_at $dt NULL,
                created_at $dt NOT NULL,
                updated_at $dt NOT NULL
            )$end",
            "CREATE TABLE IF NOT EXISTS recipe_items (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                product_id $fk NOT NULL,
                ingredient_id $fk NOT NULL,
                quantity $qty NOT NULL,
                FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
                FOREIGN KEY (ingredient_id) REFERENCES products(id)
            )$end",
            "CREATE TABLE IF NOT EXISTS customers (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                type VARCHAR(2) NOT NULL DEFAULT 'pj',
                name VARCHAR(200) NOT NULL,
                trade_name VARCHAR(200) NULL,
                document VARCHAR(14) NULL,
                state_registration VARCHAR(30) NULL,
                email VARCHAR(190) NULL,
                phone VARCHAR(40) NULL,
                cep VARCHAR(8) NULL,
                street VARCHAR(200) NULL,
                number VARCHAR(20) NULL,
                complement VARCHAR(200) NULL,
                district VARCHAR(100) NULL,
                city VARCHAR(100) NULL,
                state VARCHAR(2) NULL,
                notes VARCHAR(500) NULL,
                active TINYINT NOT NULL DEFAULT 1,
                deleted_at $dt NULL,
                created_at $dt NOT NULL,
                updated_at $dt NOT NULL
            )$end",
            "CREATE TABLE IF NOT EXISTS suppliers (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                type VARCHAR(2) NOT NULL DEFAULT 'pj',
                name VARCHAR(200) NOT NULL,
                trade_name VARCHAR(200) NULL,
                document VARCHAR(14) NULL,
                state_registration VARCHAR(30) NULL,
                email VARCHAR(190) NULL,
                phone VARCHAR(40) NULL,
                cep VARCHAR(8) NULL,
                street VARCHAR(200) NULL,
                number VARCHAR(20) NULL,
                complement VARCHAR(200) NULL,
                district VARCHAR(100) NULL,
                city VARCHAR(100) NULL,
                state VARCHAR(2) NULL,
                notes VARCHAR(500) NULL,
                active TINYINT NOT NULL DEFAULT 1,
                deleted_at $dt NULL,
                created_at $dt NOT NULL,
                updated_at $dt NOT NULL
            )$end",
            "CREATE TABLE IF NOT EXISTS sales (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                number INT NOT NULL DEFAULT 0,
                customer_id $fk NULL,
                customer VARCHAR(160) NULL,
                payment_method VARCHAR(30) NOT NULL,
                installments INT NOT NULL DEFAULT 1,
                installment_days INT NOT NULL DEFAULT 30,
                installment_plan TEXT NULL,
                installment_entry TINYINT NOT NULL DEFAULT 0,
                payment_terms VARCHAR(160) NULL,
                subtotal $money NOT NULL,
                discount $money NOT NULL DEFAULT 0,
                surcharge $money NOT NULL DEFAULT 0,
                extras TEXT NULL,
                total $money NOT NULL,
                quote_id $fk NULL,
                cost_total $cost NOT NULL DEFAULT 0,
                note TEXT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'ok',
                user_id $fk NULL,
                canceled_by $fk NULL,
                canceled_at $dt NULL,
                created_at $dt NOT NULL
            )$end",
            "CREATE TABLE IF NOT EXISTS sale_items (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                sale_id $fk NOT NULL,
                product_id $fk NOT NULL,
                quantity $qty NOT NULL,
                unit_price $money NOT NULL,
                unit_cost $cost NOT NULL,
                total $money NOT NULL,
                FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
                FOREIGN KEY (product_id) REFERENCES products(id)
            )$end",
            "CREATE TABLE IF NOT EXISTS invoices (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                access_key VARCHAR(44) NULL,
                kind VARCHAR(10) NOT NULL,
                number VARCHAR(20) NULL,
                series VARCHAR(10) NULL,
                supplier_name VARCHAR(200) NULL,
                supplier_cnpj VARCHAR(20) NULL,
                supplier_id $fk NULL,
                issued_at $dt NULL,
                total $money NOT NULL DEFAULT 0,
                user_id $fk NULL,
                created_at $dt NOT NULL
            )$end",
            "CREATE TABLE IF NOT EXISTS invoice_items (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                invoice_id $fk NOT NULL,
                product_id $fk NOT NULL,
                supplier_code VARCHAR(60) NULL,
                description VARCHAR(255) NULL,
                invoice_unit VARCHAR(10) NULL,
                invoice_qty $qty NOT NULL,
                factor $qty NOT NULL DEFAULT 1,
                stock_qty $qty NOT NULL,
                unit_cost $cost NOT NULL,
                total $money NOT NULL,
                FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE,
                FOREIGN KEY (product_id) REFERENCES products(id)
            )$end",
            "CREATE TABLE IF NOT EXISTS supplier_map (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                supplier_cnpj VARCHAR(20) NOT NULL,
                supplier_code VARCHAR(60) NOT NULL,
                product_id $fk NOT NULL,
                factor $qty NOT NULL DEFAULT 1,
                CONSTRAINT uq_supplier_map UNIQUE (company_id, supplier_cnpj, supplier_code),
                FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
            )$end",
            "CREATE TABLE IF NOT EXISTS product_refs (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                product_id $fk NOT NULL,
                manufacturer VARCHAR(80) NULL,
                code VARCHAR(80) NOT NULL,
                code_norm VARCHAR(80) NOT NULL,
                FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
            )$end",
            "CREATE TABLE IF NOT EXISTS customer_portal (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                customer_id $fk NOT NULL UNIQUE,
                password_hash VARCHAR(255) NULL,
                invite_hash VARCHAR(64) NULL,
                invite_expires $dt NULL,
                last_login $dt NULL,
                created_at $dt NOT NULL,
                FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
            )$end",
            "CREATE TABLE IF NOT EXISTS portal_requests (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                customer_id $fk NOT NULL,
                message TEXT NOT NULL,
                created_at $dt NOT NULL,
                resolved_at $dt NULL,
                resolved_by $fk NULL,
                FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
            )$end",
            "CREATE TABLE IF NOT EXISTS settings (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                name VARCHAR(60) NOT NULL,
                value " . ($my ? 'MEDIUMTEXT' : 'TEXT') . " NULL
            )$end",
            "CREATE TABLE IF NOT EXISTS quotes (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                number INT NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT 'rascunho',
                customer_id $fk NULL,
                lead_name VARCHAR(200) NULL,
                lead_phone VARCHAR(40) NULL,
                lead_email VARCHAR(190) NULL,
                user_id $fk NULL,
                created_by $fk NULL,
                request_date DATE NOT NULL,
                expires_at DATE NULL,
                external_code VARCHAR(60) NULL,
                payment_methods VARCHAR(500) NULL,
                installments INT NOT NULL DEFAULT 1,
                installment_days INT NOT NULL DEFAULT 30,
                installment_plan TEXT NULL,
                installment_entry TINYINT NOT NULL DEFAULT 0,
                payment_terms VARCHAR(160) NULL,
                discount_value $money NOT NULL DEFAULT 0,
                discount_type VARCHAR(10) NOT NULL DEFAULT 'valor',
                products_total $money NOT NULL DEFAULT 0,
                services_total $money NOT NULL DEFAULT 0,
                extras_total $money NOT NULL DEFAULT 0,
                discount_total $money NOT NULL DEFAULT 0,
                total $money NOT NULL DEFAULT 0,
                notes TEXT NULL,
                internal_notes TEXT NULL,
                public_token VARCHAR(40) NOT NULL UNIQUE,
                sale_id $fk NULL,
                service_order_id $fk NULL,
                client_response VARCHAR(500) NULL,
                status_changed_at $dt NULL,
                sent_at $dt NULL,
                approved_at $dt NULL,
                refused_at $dt NULL,
                created_at $dt NOT NULL,
                updated_at $dt NOT NULL
            )$end",
            "CREATE TABLE IF NOT EXISTS quote_items (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                quote_id $fk NOT NULL,
                product_id $fk NOT NULL,
                kind VARCHAR(10) NOT NULL,
                description VARCHAR(255) NOT NULL,
                unit VARCHAR(10) NULL,
                quantity $qty NOT NULL,
                unit_price $money NOT NULL,
                discount $money NOT NULL DEFAULT 0,
                discount_type VARCHAR(10) NOT NULL DEFAULT 'valor',
                discount_value $money NOT NULL DEFAULT 0,
                total $money NOT NULL,
                sort INT NOT NULL DEFAULT 0,
                FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE,
                FOREIGN KEY (product_id) REFERENCES products(id)
            )$end",
            "CREATE TABLE IF NOT EXISTS quote_extras (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                quote_id $fk NOT NULL,
                description VARCHAR(200) NOT NULL,
                amount $money NOT NULL,
                FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE
            )$end",
            "CREATE TABLE IF NOT EXISTS financial_entries (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                type VARCHAR(10) NOT NULL,
                status VARCHAR(12) NOT NULL DEFAULT 'aberto',
                description VARCHAR(200) NOT NULL,
                category VARCHAR(80) NULL,
                customer_id $fk NULL,
                supplier_id $fk NULL,
                amount $money NOT NULL,
                due_date DATE NOT NULL,
                paid_at DATE NULL,
                paid_amount $money NULL,
                payment_method VARCHAR(30) NULL,
                installment INT NOT NULL DEFAULT 1,
                installments INT NOT NULL DEFAULT 1,
                group_key VARCHAR(40) NULL,
                sale_id $fk NULL,
                invoice_id $fk NULL,
                notes VARCHAR(500) NULL,
                user_id $fk NULL,
                created_at $dt NOT NULL,
                updated_at $dt NOT NULL
            )$end",
            "CREATE TABLE IF NOT EXISTS equipments (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                customer_id $fk NOT NULL,
                name VARCHAR(120) NOT NULL,
                brand VARCHAR(80) NULL,
                model VARCHAR(80) NULL,
                serial VARCHAR(80) NULL,
                category VARCHAR(80) NULL,
                valid_until VARCHAR(10) NULL,
                warranty_until VARCHAR(10) NULL,
                specs $big NULL,
                notes VARCHAR(500) NULL,
                active TINYINT NOT NULL DEFAULT 1,
                deleted_at $dt NULL,
                created_at $dt NOT NULL,
                updated_at $dt NOT NULL
            )$end",
            "CREATE TABLE IF NOT EXISTS checklist_models (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                name VARCHAR(120) NOT NULL,
                service_type VARCHAR(20) NULL,
                items $big NOT NULL,
                active TINYINT NOT NULL DEFAULT 1,
                created_at $dt NOT NULL,
                updated_at $dt NOT NULL
            )$end",
            "CREATE TABLE IF NOT EXISTS questionnaires (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                name VARCHAR(120) NOT NULL,
                questions $big NOT NULL,
                show_os TINYINT NOT NULL DEFAULT 1,
                per_line TINYINT NOT NULL DEFAULT 1,
                pairs TINYINT NOT NULL DEFAULT 0,
                show_unanswered TINYINT NOT NULL DEFAULT 1,
                allow_equipment TINYINT NOT NULL DEFAULT 0,
                equipment_required TINYINT NOT NULL DEFAULT 0,
                active TINYINT NOT NULL DEFAULT 1,
                deleted_at $dt NULL,
                created_at $dt NOT NULL,
                updated_at $dt NOT NULL
            )$end",
            "CREATE TABLE IF NOT EXISTS task_types (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                name VARCHAR(120) NOT NULL,
                tolerance_min INT NULL,
                duration_min INT NULL,
                questionnaire_ids $big NULL,
                requires VARCHAR(500) NULL,
                send_survey TINYINT NOT NULL DEFAULT 0,
                send_os_email TINYINT NOT NULL DEFAULT 0,
                active TINYINT NOT NULL DEFAULT 1,
                deleted_at $dt NULL,
                created_at $dt NOT NULL,
                updated_at $dt NOT NULL
            )$end",
            "CREATE TABLE IF NOT EXISTS service_orders (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                number INT NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT 'aberta',
                origin VARCHAR(12) NOT NULL DEFAULT 'chamado',
                customer_id $fk NOT NULL,
                equipment_id $fk NULL,
                quote_id $fk NULL,
                service_type VARCHAR(20) NULL,
                checklist_model_id $fk NULL,
                task_type_id $fk NULL,
                technician_id $fk NULL,
                scheduled_at $dt NULL,
                due_date DATE NULL,
                description TEXT NOT NULL,
                notes VARCHAR(500) NULL,
                user_id $fk NULL,
                finished_at $dt NULL,
                started_at $dt NULL,
                report TEXT NULL,
                signature $big NULL,
                signer_name VARCHAR(120) NULL,
                signer_doc VARCHAR(20) NULL,
                km DECIMAL(10,1) NULL,
                public_token VARCHAR(32) NULL,
                survey_rating INT NULL,
                survey_comment TEXT NULL,
                survey_at $dt NULL,
                created_at $dt NOT NULL,
                updated_at $dt NOT NULL
            )$end",
            "CREATE TABLE IF NOT EXISTS so_answers (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                order_id $fk NOT NULL,
                questionnaire_id $fk NOT NULL,
                equipment_id $fk NULL,
                answers $big NOT NULL,
                user_id $fk NULL,
                updated_at $dt NOT NULL
            )$end",
            "CREATE TABLE IF NOT EXISTS so_photos (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                order_id $fk NOT NULL,
                image $big NOT NULL,
                caption VARCHAR(200) NULL,
                user_id $fk NULL,
                created_at $dt NOT NULL
            )$end",
            "CREATE TABLE IF NOT EXISTS so_items (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                order_id $fk NOT NULL,
                kind VARCHAR(10) NOT NULL,
                product_id $fk NULL,
                description VARCHAR(200) NOT NULL,
                quantity DECIMAL(12,3) NOT NULL DEFAULT 1,
                unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
                user_id $fk NULL,
                created_at $dt NOT NULL
            )$end",
            "CREATE TABLE IF NOT EXISTS so_events (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                order_id $fk NOT NULL,
                from_status VARCHAR(20) NULL,
                to_status VARCHAR(20) NOT NULL,
                user_id $fk NULL,
                created_at $dt NOT NULL,
                original_at $dt NULL,
                note VARCHAR(300) NULL,
                reason VARCHAR(120) NULL,
                manual INT NOT NULL DEFAULT 0,
                edited_by $fk NULL,
                edited_at $dt NULL,
                deleted_by $fk NULL,
                deleted_at $dt NULL
            )$end",
            "CREATE TABLE IF NOT EXISTS so_sends (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                order_id $fk NOT NULL,
                kind VARCHAR(12) NOT NULL,
                recipient VARCHAR(200) NOT NULL,
                ok INT NOT NULL DEFAULT 1,
                error VARCHAR(300) NULL,
                user_id $fk NULL,
                created_at $dt NOT NULL
            )$end",
            "CREATE TABLE IF NOT EXISTS purchase_orders (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                number INT NOT NULL DEFAULT 0,
                status VARCHAR(20) NOT NULL DEFAULT 'rascunho',
                supplier_id $fk NULL,
                order_date DATE NOT NULL,
                expected_date DATE NULL,
                payment_method VARCHAR(30) NULL,
                payment_terms VARCHAR(60) NULL,
                items_total $money NOT NULL DEFAULT 0,
                freight $money NOT NULL DEFAULT 0,
                discount $money NOT NULL DEFAULT 0,
                total $money NOT NULL DEFAULT 0,
                notes TEXT NULL,
                user_id $fk NULL,
                public_token VARCHAR(32) NULL,
                sent_at $dt NULL,
                confirmed_at $dt NULL,
                supplier_comment VARCHAR(500) NULL,
                received_at DATE NULL,
                created_at $dt NOT NULL,
                updated_at $dt NOT NULL
            )$end",
            "CREATE TABLE IF NOT EXISTS purchase_order_items (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                purchase_order_id $fk NOT NULL,
                product_id $fk NOT NULL,
                description VARCHAR(255) NOT NULL,
                unit VARCHAR(10) NULL,
                quantity $qty NOT NULL,
                unit_cost $cost NOT NULL,
                total $money NOT NULL,
                sort INT NOT NULL DEFAULT 0,
                FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
                FOREIGN KEY (product_id) REFERENCES products(id)
            )$end",
            "CREATE TABLE IF NOT EXISTS payment_methods (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                code VARCHAR(30) NOT NULL,
                name VARCHAR(60) NOT NULL,
                active TINYINT NOT NULL DEFAULT 1,
                max_installments INT NOT NULL DEFAULT 1,
                interval_mode VARCHAR(10) NOT NULL DEFAULT 'fixo',
                sort INT NOT NULL DEFAULT 0,
                created_at $dt NOT NULL,
                updated_at $dt NOT NULL
            )$end",
            "CREATE TABLE IF NOT EXISTS payment_terms (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                name VARCHAR(160) NOT NULL,
                installments INT NULL,
                interval_days INT NULL,
                entry TINYINT NOT NULL DEFAULT 0,
                entry_percent $money NULL,
                active TINYINT NOT NULL DEFAULT 1,
                sort INT NOT NULL DEFAULT 0,
                created_at $dt NOT NULL,
                updated_at $dt NOT NULL
            )$end",
            "CREATE TABLE IF NOT EXISTS quote_statuses (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                code VARCHAR(20) NOT NULL,
                name VARCHAR(60) NOT NULL,
                color VARCHAR(9) NOT NULL,
                active TINYINT NOT NULL DEFAULT 1,
                sort INT NOT NULL DEFAULT 0,
                created_at $dt NOT NULL,
                updated_at $dt NOT NULL
            )$end",
            "CREATE TABLE IF NOT EXISTS movements (
                id $pk,
                company_id $fk NOT NULL DEFAULT 0,
                product_id $fk NOT NULL,
                type VARCHAR(30) NOT NULL,
                quantity $qty NOT NULL,
                unit_cost $cost NOT NULL DEFAULT 0,
                balance_after $qty NOT NULL,
                note VARCHAR(255) NULL,
                sale_id $fk NULL,
                invoice_id $fk NULL,
                user_id $fk NULL,
                created_at $dt NOT NULL,
                FOREIGN KEY (product_id) REFERENCES products(id)
            )$end",
            "CREATE INDEX idx_mov_product ON movements (product_id, created_at)",
            "CREATE INDEX idx_mov_created ON movements (created_at)",
            "CREATE INDEX idx_sales_created ON sales (created_at)",
            "CREATE INDEX idx_products_barcode ON products (barcode)",
            "CREATE INDEX idx_sales_customer ON sales (customer_id)",
            "CREATE INDEX idx_customers_name ON customers (name)",
            "CREATE INDEX idx_quotes_status ON quotes (status)",
            "CREATE INDEX idx_fin_due ON financial_entries (type, status, due_date)",
            "CREATE INDEX idx_fin_paid ON financial_entries (paid_at)",
            "CREATE INDEX idx_fin_sale ON financial_entries (sale_id)",
            "CREATE INDEX idx_fin_invoice ON financial_entries (invoice_id)",
            "CREATE INDEX idx_po_status ON purchase_orders (status)",
            "CREATE INDEX idx_po_token ON purchase_orders (public_token)",
            "CREATE INDEX idx_poi_po ON purchase_order_items (purchase_order_id)",
            "CREATE INDEX idx_suppliers_name ON suppliers (name)",
            "CREATE INDEX idx_invoices_supplier ON invoices (supplier_id)",
            "CREATE INDEX idx_quote_items_quote ON quote_items (quote_id)",
            "CREATE INDEX idx_prefs_product ON product_refs (product_id)",
            "CREATE INDEX idx_prefs_code ON product_refs (code_norm)",
            "CREATE INDEX idx_portal_req_customer ON portal_requests (customer_id)",
            "CREATE INDEX idx_equip_customer ON equipments (customer_id)",
            "CREATE INDEX idx_so_status ON service_orders (status)",
            "CREATE INDEX idx_so_customer ON service_orders (customer_id)",
            "CREATE INDEX idx_so_technician ON service_orders (technician_id)",
            "CREATE INDEX idx_so_token ON service_orders (public_token)",
            "CREATE INDEX idx_quotes_so ON quotes (service_order_id)",
            "CREATE INDEX idx_soa_order ON so_answers (order_id)",
            "CREATE INDEX idx_sop_order ON so_photos (order_id)",
            "CREATE INDEX idx_soi_order ON so_items (order_id)",
            "CREATE INDEX idx_soe_order ON so_events (order_id)",
            "CREATE INDEX idx_sos_order ON so_sends (order_id)",
            "CREATE INDEX idx_users_company ON users (company_id)",
            // Chaves naturais únicas só dentro da empresa
            "CREATE UNIQUE INDEX uq_customers_doc ON customers (company_id, document)",
            "CREATE UNIQUE INDEX uq_suppliers_doc ON suppliers (company_id, document)",
            "CREATE UNIQUE INDEX uq_invoices_key ON invoices (company_id, access_key)",
            "CREATE UNIQUE INDEX uq_settings_name ON settings (company_id, name)",
            "CREATE UNIQUE INDEX uq_pmethods_code ON payment_methods (company_id, code)",
            "CREATE UNIQUE INDEX uq_pprofiles_name ON permission_profiles (company_id, name)",
            "CREATE UNIQUE INDEX uq_so_number ON service_orders (company_id, number)",
            "CREATE UNIQUE INDEX uq_quotes_number ON quotes (company_id, number)",
            "CREATE UNIQUE INDEX uq_sales_number ON sales (company_id, number)",
            "CREATE UNIQUE INDEX uq_po_number ON purchase_orders (company_id, number)",
        ];
        foreach (Tenant::TABLES as $t) $list[] = "CREATE INDEX idx_{$t}_company ON $t (company_id)";
        return $list;
    }

    /** Atualiza bancos criados por versões anteriores. */
    public static function upgrade(): void
    {
        self::$upgrading = true;
        $prev = Tenant::id();
        try {
            // 1) colunas company_id nas tabelas que já existem; 2) ajustes das versões antigas, na "empresa 0"
            // (as linhas antigas ainda não têm empresa); 3) empresa #1 para os dados que já existiam
            $changed = self::upgradeCompanyColumns();
            // Banco de uma empresa só (usuários, nenhuma empresa ainda): os ajustes antigos rodam na "empresa 0" e depois
            // tudo vai para a empresa #1. Em banco multiempresa ou recém-criado, os ajustes antigos não semeiam dados.
            $fresh = false;
            try { $legacy = !(int)self::val('SELECT COUNT(*) FROM companies') && (int)self::val('SELECT COUNT(*) FROM users'); }
            catch (PDOException $e) { $legacy = false; $fresh = true; }   // banco vazio (ainda sem tabelas): o instalador cria tudo já atual
            Tenant::set($legacy ? 0 : null);
            if (!$fresh) self::upgradeLegacy();
            Tenant::set($prev);
            if ($changed || ($legacy && self::hasUnassignedRows())) self::upgradeCompanyData();
            if (!$fresh) self::upgradeOrderNumbers();
        } finally {
            self::$upgrading = false;
            Tenant::set($prev);
        }
    }

    /**
     * v3.6.0 (multiempresa): tabela companies, coluna company_id em todas as tabelas de dados,
     * chaves únicas por empresa e empresa #1 para os dados que já existiam.
     */
    private static function upgradeCompanyColumns(): bool
    {
        $my = self::$driver === 'mysql';
        $pdo = self::pdo();
        try {
            $pdo->query('SELECT id FROM companies LIMIT 1');
        } catch (PDOException $e) {
            foreach (self::schema() as $sql) {
                if (str_starts_with($sql, 'CREATE TABLE IF NOT EXISTS companies ')) $pdo->exec($sql);
            }
        }
        $need = [];
        foreach ([...Tenant::TABLES, 'users'] as $t) {
            try { $pdo->query("SELECT id FROM $t LIMIT 1"); }
            catch (PDOException $e) { continue; }   // tabela ainda não existe: nasce com company_id (migrate)
            try { $pdo->query("SELECT company_id FROM $t LIMIT 1"); }
            catch (PDOException $e) { $need[] = $t; }
        }
        if (!$need) return false;

        // Tabelas com chave única global que passa a valer só dentro da empresa
        $uniqueCols = ['customers' => 'document', 'suppliers' => 'document', 'invoices' => 'access_key',
            'supplier_map' => 'supplier_cnpj', 'settings' => 'name', 'payment_methods' => 'code', 'permission_profiles' => 'name'];
        $fk = $my ? 'INT UNSIGNED' : 'INTEGER';
        foreach ($need as $t) {
            if ($my) {
                $pdo->exec("ALTER TABLE $t ADD COLUMN company_id $fk " . ($t === 'users' ? 'NULL' : 'NOT NULL DEFAULT 0'));
                if (isset($uniqueCols[$t])) {
                    foreach (self::all("SHOW INDEX FROM $t") as $ix) {
                        if ((int)$ix['Non_unique'] === 0 && $ix['Column_name'] === $uniqueCols[$t] && (int)$ix['Seq_in_index'] === 1 && $ix['Key_name'] !== 'PRIMARY') {
                            $pdo->exec("ALTER TABLE $t DROP INDEX `{$ix['Key_name']}`");
                        }
                    }
                    if ($t === 'supplier_map') $pdo->exec('ALTER TABLE supplier_map ADD CONSTRAINT uq_supplier_map UNIQUE (company_id, supplier_cnpj, supplier_code)');
                }
            } elseif (isset($uniqueCols[$t])) {
                self::rebuildSqlite($t);   // SQLite não troca constraint: recria a tabela já com company_id
            } else {
                $pdo->exec("ALTER TABLE $t ADD COLUMN company_id $fk " . ($t === 'users' ? 'NULL' : 'NOT NULL DEFAULT 0'));
            }
        }
        return true;
    }

    /** v3.7.0/3.8.0: número por empresa (OS, orçamento, venda e ordem de compra) começando em 1, na ordem de criação. O `id` segue sendo o identificador interno. */
    private static function upgradeOrderNumbers(): void
    {
        $pdo = self::pdo();
        $added = false;
        foreach (self::NUMBERED as $t) {
            try { $pdo->query("SELECT number FROM $t LIMIT 1"); continue; }
            catch (PDOException $e) { /* ainda não tem a coluna */ }
            $pdo->exec("ALTER TABLE $t ADD COLUMN number INT NOT NULL DEFAULT 0");
            $n = [];
            foreach (self::all("SELECT id, company_id FROM $t ORDER BY id") as $r) {
                $c = (int)$r['company_id'];
                $n[$c] = ($n[$c] ?? 0) + 1;
                self::run("UPDATE $t SET number = ? WHERE id = ?", [$n[$c], (int)$r['id']]);
            }
            $added = true;
        }
        if ($added) self::migrate(); // índices únicos (empresa, número)
    }

    /** Sobrou linha sem empresa (company_id = 0), por exemplo criada pelos ajustes de versões antigas? Uma consulta só. */
    private static function hasUnassignedRows(): bool
    {
        $parts = array_map(fn($t) => "EXISTS (SELECT 1 FROM $t WHERE company_id = 0)", Tenant::TABLES);
        try { return (bool)(int)self::val('SELECT ' . implode(' OR ', $parts)); }
        catch (PDOException $e) { return false; }
    }

    /** Depois do upgradeLegacy: índices por empresa, a empresa #1 (nome e CNPJ das configurações antigas) e o vínculo dos dados antigos. */
    private static function upgradeCompanyData(): void
    {
        self::migrate(); // tabelas novas e índices (por empresa e únicos por empresa)

        // Os dados que já existiam viram a empresa #1 (nome e CNPJ vêm das configurações antigas)
        if (!(int)self::val('SELECT COUNT(*) FROM companies') && (int)self::val('SELECT COUNT(*) FROM users')) {
            $name = trim((string)self::val("SELECT value FROM settings WHERE name = 'company_name' AND company_id = 0")) ?: 'Empresa principal';
            $doc = preg_replace('/\D/', '', (string)self::val("SELECT value FROM settings WHERE name = 'company_document' AND company_id = 0"));
            $slug = trim((string)preg_replace('/[^a-z0-9]+/', '-', strtolower((string)iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name))), '-') ?: 'empresa';
            self::run('INSERT INTO companies (name, slug, document, active, created_at) VALUES (?, ?, ?, 1, ?)',
                [$name, $slug, $doc !== '' ? $doc : null, now()]);
        }
        $first = (int)self::val('SELECT MIN(id) FROM companies');
        if ($first) {
            foreach (Tenant::TABLES as $t) self::run("UPDATE $t SET company_id = ? WHERE company_id = 0", [$first]);
            self::run("UPDATE users SET company_id = ? WHERE company_id IS NULL AND role <> 'superadmin'", [$first]);
        }
    }

    /** SQLite: recria a tabela com a definição atual de schema() (chaves únicas novas) e copia os dados. */
    private static function rebuildSqlite(string $t): void
    {
        $pdo = self::pdo();
        $create = null;
        foreach (self::schema() as $sql) {
            if (str_starts_with($sql, "CREATE TABLE IF NOT EXISTS $t (")) { $create = $sql; break; }
        }
        if ($create === null) throw new RuntimeException("Tabela $t não encontrada em schema()");
        $old = array_column(self::all("PRAGMA table_info($t)"), 'name');
        $new = str_replace("CREATE TABLE IF NOT EXISTS $t (", "CREATE TABLE {$t}__new (", $create);
        $pdo->exec('PRAGMA foreign_keys = OFF');
        try {
            $pdo->beginTransaction();
            $pdo->exec("DROP TABLE IF EXISTS {$t}__new");
            $pdo->exec($new);
            $newCols = array_column(self::all("PRAGMA table_info({$t}__new)"), 'name');
            $cols = implode(',', array_intersect($old, $newCols));
            $pdo->exec("INSERT INTO {$t}__new ($cols) SELECT $cols FROM $t");
            $pdo->exec("DROP TABLE $t");
            $pdo->exec("ALTER TABLE {$t}__new RENAME TO $t");
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        } finally {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }
    }

    private static function upgradeLegacy(): void
    {
        // v1.1.0: ordens de serviço (equipamentos, modelos de checklist e OS)
        try {
            self::pdo()->query('SELECT id FROM service_orders LIMIT 1');
        } catch (PDOException $e) {
            self::migrate();
            // O perfil "Acesso total" ganha a área nova e entra o perfil "Técnico"
            $p = self::one("SELECT id FROM permission_profiles WHERE name = 'Acesso total'");
            if ($p) self::update('permission_profiles', (int)$p['id'], ['permissions' => Auth::normalizePerms(array_keys(Auth::perms()))]);
            Auth::seedProfiles();
        }
        // Perfis de exemplo novos (ex.: "Técnico") entram também em instalações que já têm as ordens de serviço
        try {
            if (Tenant::id() !== null) Auth::seedProfiles();   // só no banco antigo (empresa 0)
        } catch (PDOException $e) { /* tabela ainda não existe */ }
        // v1.25.0: portal do cliente (acesso e pedidos de correção)
        try {
            self::pdo()->query('SELECT id FROM customer_portal LIMIT 1');
        } catch (PDOException $e) {
            self::migrate();
        }
        try {
            self::pdo()->query('SELECT markup FROM products LIMIT 1');
        } catch (PDOException $e) {
            self::pdo()->exec('ALTER TABLE products ADD COLUMN markup DECIMAL(8,2) NULL');
        }
        // v1.3: cadastro de clientes
        try {
            self::pdo()->query('SELECT customer_id FROM sales LIMIT 1');
        } catch (PDOException $e) {
            self::pdo()->exec('ALTER TABLE sales ADD COLUMN customer_id ' . (self::$driver === 'mysql' ? 'INT UNSIGNED' : 'INTEGER') . ' NULL');
            self::migrate(); // cria a tabela customers e os índices novos
            Customers::ensureDefault();
        }
        // v1.4: orçamentos, serviços e acréscimo nas vendas
        try {
            self::pdo()->query('SELECT quote_id FROM sales LIMIT 1');
        } catch (PDOException $e) {
            $fk = self::$driver === 'mysql' ? 'INT UNSIGNED' : 'INTEGER';
            self::pdo()->exec('ALTER TABLE sales ADD COLUMN surcharge DECIMAL(14,2) NOT NULL DEFAULT 0');
            self::pdo()->exec("ALTER TABLE sales ADD COLUMN quote_id $fk NULL");
            self::migrate(); // cria settings, quotes, quote_items e quote_extras
        }
        // v1.5: fornecedores e permissões por usuário
        try {
            self::pdo()->query('SELECT permissions FROM users LIMIT 1');
        } catch (PDOException $e) {
            $fk = self::$driver === 'mysql' ? 'INT UNSIGNED' : 'INTEGER';
            self::pdo()->exec('ALTER TABLE users ADD COLUMN permissions TEXT NULL');
            self::pdo()->exec("ALTER TABLE users ADD COLUMN profile_id $fk NULL");
            self::pdo()->exec("ALTER TABLE quote_items ADD COLUMN discount_type VARCHAR(10) NOT NULL DEFAULT 'valor'");
            self::pdo()->exec('ALTER TABLE quote_items ADD COLUMN discount_value DECIMAL(14,2) NOT NULL DEFAULT 0');
            self::pdo()->exec('UPDATE quote_items SET discount_value = discount');
            self::pdo()->exec("ALTER TABLE invoices ADD COLUMN supplier_id $fk NULL");
            self::migrate(); // cria as tabelas suppliers e permission_profiles
            Auth::seedProfiles();
            // Fornecedores das notas já importadas
            foreach (self::all('SELECT id, supplier_cnpj, supplier_name FROM invoices') as $inv) {
                $sid = Customers::supplierFor($inv['supplier_cnpj'], $inv['supplier_name']);
                if ($sid) self::update('invoices', (int)$inv['id'], ['supplier_id' => $sid]);
            }
        }
        // v1.5: perfis de permissão
        try {
            self::pdo()->query('SELECT profile_id FROM users LIMIT 1');
        } catch (PDOException $e) {
            $fk = self::$driver === 'mysql' ? 'INT UNSIGNED' : 'INTEGER';
            self::pdo()->exec("ALTER TABLE users ADD COLUMN profile_id $fk NULL");
        }
        try {
            self::pdo()->query('SELECT id FROM permission_profiles LIMIT 1');
        } catch (PDOException $e) {
            self::migrate(); // cria a tabela de perfis
            Auth::seedProfiles();
        }
        // v1.8: financeiro e ordens de compra
        try {
            self::pdo()->query('SELECT id FROM financial_entries LIMIT 1');
        } catch (PDOException $e) {
            self::migrate();
            // O perfil "Acesso total" ganha as áreas novas
            $p = self::one("SELECT id, permissions FROM permission_profiles WHERE name = 'Acesso total'");
            if ($p) self::update('permission_profiles', (int)$p['id'], ['permissions' => Auth::normalizePerms(array_keys(Auth::perms()))]);
            // Vendas antigas entram no caixa como recebidas
            foreach (self::all("SELECT id FROM sales WHERE status = 'ok'") as $sl) Finance::fromSale((int)$sl['id']);
        }
        // v1.3.0: cadastro de equipamentos (categoria, validade, garantia e especificações)
        try {
            self::pdo()->query('SELECT warranty_until FROM equipments LIMIT 1');
        } catch (PDOException $e) {
            $big = self::$driver === 'mysql' ? 'MEDIUMTEXT' : 'TEXT';
            foreach (["ALTER TABLE equipments ADD COLUMN category VARCHAR(80) NULL",
                "ALTER TABLE equipments ADD COLUMN valid_until VARCHAR(10) NULL",
                "ALTER TABLE equipments ADD COLUMN warranty_until VARCHAR(10) NULL",
                "ALTER TABLE equipments ADD COLUMN specs $big NULL"] as $sql) {
                try { self::pdo()->exec($sql); } catch (PDOException $e2) { /* já existe */ }
            }
        }
        // v1.5.0: tipos de tarefa e questionários (substituem os modelos de checklist)
        try {
            self::pdo()->query('SELECT task_type_id FROM service_orders LIMIT 1');
        } catch (PDOException $e) {
            $fk = self::$driver === 'mysql' ? 'INT UNSIGNED' : 'INTEGER';
            try { self::pdo()->exec("ALTER TABLE service_orders ADD COLUMN task_type_id $fk NULL"); } catch (PDOException $e2) { /* tabela ainda não existe */ }
        }
        try {
            self::pdo()->query('SELECT id FROM task_types LIMIT 1');
        } catch (PDOException $e) {
            self::migrate();
        }
        // Tipos de tarefa ainda não criados (atualização ou instalação nova): cria os padrões e converte os checklists antigos
        if (Tenant::id() !== null && !(int)self::val('SELECT COUNT(*) FROM task_types WHERE company_id = ?', [Tenant::id()])) TaskTypes::importLegacy();
        // v2.0.0: OS não fatura (só o orçamento); "faturada" vira "concluída". Orçamento guarda a OS de onde nasceu.
        try {
            self::pdo()->query('SELECT service_order_id FROM quotes LIMIT 1');
        } catch (PDOException $e) {
            $fkc = self::$driver === 'mysql' ? 'INT UNSIGNED' : 'INTEGER';
            try { self::pdo()->exec("ALTER TABLE quotes ADD COLUMN service_order_id $fkc NULL"); } catch (PDOException $e2) {}
            try { self::pdo()->exec('CREATE INDEX idx_quotes_so ON quotes (service_order_id)'); } catch (PDOException $e2) {}
        }
        try { self::run("UPDATE service_orders SET status = 'concluida' WHERE status = 'faturada'"); } catch (PDOException $e) {}
        // v3.1.0: permissões por módulo (visualizar, adicionar, editar, excluir…); listas antigas passam para o formato novo
        try { Auth::upgradePerms(); } catch (PDOException $e) { /* tabela ainda não existe */ }
        // v3.0.0: o questionário não escolhe mais equipamento (fica nos Detalhes da OS)
        try { self::run('UPDATE questionnaires SET allow_equipment = 0, equipment_required = 0 WHERE allow_equipment <> 0 OR equipment_required <> 0'); } catch (PDOException $e) { /* tabela ainda não existe */ }
        // v2.4.0: exclusão de clientes, fornecedores, equipamentos, tipos de tarefa e questionários (os já usados ficam
        // marcados com deleted_at e continuam aparecendo onde foram usados)
        foreach (['customers', 'suppliers', 'equipments', 'questionnaires', 'task_types'] as $t) {
            try {
                self::pdo()->query("SELECT deleted_at FROM $t LIMIT 1");
            } catch (PDOException $e) {
                $dtc = self::$driver === 'mysql' ? 'DATETIME' : 'TEXT';
                try { self::pdo()->exec("ALTER TABLE $t ADD COLUMN deleted_at $dtc NULL"); } catch (PDOException $e2) { /* tabela ainda não existe */ }
            }
        }
        // v1.10.0: ajuste manual do controle de horas (horário original, justificativa, motivo da pausa, inclusão e exclusão de pausas)
        try {
            self::pdo()->query('SELECT deleted_at FROM so_events LIMIT 1');
        } catch (PDOException $e) {
            $dtc = self::$driver === 'mysql' ? 'DATETIME' : 'TEXT';
            $fkc = self::$driver === 'mysql' ? 'INT UNSIGNED' : 'INTEGER';
            foreach (["original_at $dtc NULL", 'note VARCHAR(300) NULL', 'reason VARCHAR(120) NULL', 'manual INT NOT NULL DEFAULT 0',
                      "edited_by $fkc NULL", "edited_at $dtc NULL", "deleted_by $fkc NULL", "deleted_at $dtc NULL"] as $col) {
                try { self::pdo()->exec("ALTER TABLE so_events ADD COLUMN $col"); } catch (PDOException $e2) { /* tabela ainda não existe ou coluna já existe */ }
            }
        }
        // v1.8.0: execução da OS (relato, assinatura, km, link público, pesquisa) e tabelas de respostas, fotos, itens, histórico e envios
        try {
            self::pdo()->query('SELECT public_token FROM service_orders LIMIT 1');
        } catch (PDOException $e) {
            $bigc = self::$driver === 'mysql' ? 'MEDIUMTEXT' : 'TEXT';
            $dtc = self::$driver === 'mysql' ? 'DATETIME' : 'TEXT';
            foreach (["started_at $dtc NULL", 'report TEXT NULL', "signature $bigc NULL", 'signer_name VARCHAR(120) NULL', 'signer_doc VARCHAR(20) NULL',
                      'km DECIMAL(10,1) NULL', 'public_token VARCHAR(32) NULL', 'survey_rating INT NULL', 'survey_comment TEXT NULL', "survey_at $dtc NULL"] as $col) {
                try { self::pdo()->exec("ALTER TABLE service_orders ADD COLUMN $col"); } catch (PDOException $e2) { /* já existe */ }
            }
        }
        try {
            self::pdo()->query('SELECT id FROM so_sends LIMIT 1');
        } catch (PDOException $e) {
            self::migrate();
        }
        // v1.8.5: telefone dos usuários (aparece como contato do responsável no orçamento)
        try {
            self::pdo()->query('SELECT phone FROM users LIMIT 1');
        } catch (PDOException $e) {
            self::pdo()->exec('ALTER TABLE users ADD COLUMN phone VARCHAR(30) NULL');
        }
        // v1.8.5: formas de pagamento cadastráveis
        try {
            self::pdo()->query('SELECT id FROM payment_methods LIMIT 1');
        } catch (PDOException $e) {
            self::migrate();
            if (self::$driver === 'mysql') {
                try { self::pdo()->exec('ALTER TABLE quotes MODIFY payment_methods VARCHAR(500) NULL'); } catch (PDOException $e2) { /* ok */ }
            }
            $p = self::one("SELECT id FROM permission_profiles WHERE name = 'Acesso total'");
            if ($p) self::update('permission_profiles', (int)$p['id'], ['permissions' => Auth::normalizePerms(array_keys(Auth::perms()))]);
        }
        // v1.8.6: parcelamento por forma de pagamento (máximo de parcelas e intervalo)
        try {
            self::pdo()->query('SELECT max_installments FROM payment_methods LIMIT 1');
        } catch (PDOException $e) {
            foreach (["ALTER TABLE payment_methods ADD COLUMN max_installments INT NOT NULL DEFAULT 1",
                "ALTER TABLE payment_methods ADD COLUMN interval_mode VARCHAR(10) NOT NULL DEFAULT 'fixo'",
                "ALTER TABLE sales ADD COLUMN installments INT NOT NULL DEFAULT 1",
                "ALTER TABLE sales ADD COLUMN installment_days INT NOT NULL DEFAULT 30",
                "ALTER TABLE quotes ADD COLUMN installments INT NOT NULL DEFAULT 1",
                "ALTER TABLE quotes ADD COLUMN installment_days INT NOT NULL DEFAULT 30",
                "ALTER TABLE sales ADD COLUMN installment_plan TEXT NULL",
                "ALTER TABLE quotes ADD COLUMN installment_plan TEXT NULL"] as $sql) {
                try { self::pdo()->exec($sql); } catch (PDOException $e2) { /* já existe */ }
            }
            if (Tenant::id() !== null) PaymentMethods::applyDefaultInstallments();
        }
        if (Tenant::id() !== null) PaymentMethods::seed();   // só na migração de um banco antigo (empresa 0); empresa nova já nasce com os cadastros
        // v1.8.7: condições de pagamento cadastráveis; venda guarda a condição
        try {
            self::pdo()->query('SELECT id FROM payment_terms LIMIT 1');
        } catch (PDOException $e) {
            self::migrate();
            try { self::pdo()->exec('ALTER TABLE sales ADD COLUMN payment_terms VARCHAR(160) NULL'); } catch (PDOException $e2) { /* já existe */ }
        }
        // v1.8.8: entrada + parcelas (venda, orçamento e condição de pagamento)
        try {
            self::pdo()->query('SELECT installment_entry FROM sales LIMIT 1');
        } catch (PDOException $e) {
            $money = self::$driver === 'mysql' ? 'DECIMAL(14,2)' : 'NUMERIC';
            foreach (['ALTER TABLE sales ADD COLUMN installment_entry TINYINT NOT NULL DEFAULT 0',
                'ALTER TABLE quotes ADD COLUMN installment_entry TINYINT NOT NULL DEFAULT 0',
                'ALTER TABLE payment_terms ADD COLUMN entry TINYINT NOT NULL DEFAULT 0',
                "ALTER TABLE payment_terms ADD COLUMN entry_percent $money NULL"] as $sql) {
                try { self::pdo()->exec($sql); } catch (PDOException $e2) { /* já existe */ }
            }
            // Condições com entrada que passam a vir prontas (se a lista ainda estiver vazia, o seed abaixo já cria todas)
            $hasTerms = (int)self::val('SELECT COUNT(*) FROM payment_terms') > 0;
            foreach ($hasTerms ? [['Entrada + 28/56/84 dias', 28], ['Entrada + 30/60/90 dias', 30]] : [] as [$tn, $td]) {
                if (!self::val('SELECT id FROM payment_terms WHERE LOWER(name) = ?', [mb_strtolower($tn)])) {
                    self::insert('payment_terms', ['name' => $tn, 'installments' => 3, 'interval_days' => $td, 'entry' => 1, 'active' => 1,
                        'sort' => (int)self::val('SELECT COALESCE(MAX(sort), 0) + 1 FROM payment_terms'), 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
        if (Tenant::id() !== null) PaymentTerms::seed();
        // v1.9.1: status de orçamento cadastráveis (nome e cor)
        try {
            self::pdo()->query('SELECT id FROM quote_statuses LIMIT 1');
        } catch (PDOException $e) {
            self::migrate();
        }
        if (Tenant::id() !== null) QuoteStatuses::seed();
        // v1.17.0: descrição do produto
        try {
            self::pdo()->query('SELECT description FROM products LIMIT 1');
        } catch (PDOException $e) {
            self::pdo()->exec('ALTER TABLE products ADD COLUMN description TEXT NULL');
        }
        // v1.16.0: marca, código do fabricante e referências cruzadas do produto
        try {
            self::pdo()->query('SELECT brand FROM products LIMIT 1');
        } catch (PDOException $e) {
            self::pdo()->exec('ALTER TABLE products ADD COLUMN brand VARCHAR(80) NULL');
            self::pdo()->exec('ALTER TABLE products ADD COLUMN mfr_code VARCHAR(80) NULL');
            self::migrate(); // cria product_refs e os índices
        }
        // v1.13.0: cor de destaque escolhida por cada usuário
        try {
            self::pdo()->query('SELECT accent_color FROM users LIMIT 1');
        } catch (PDOException $e) {
            self::pdo()->exec('ALTER TABLE users ADD COLUMN accent_color VARCHAR(7) NULL');
        }
        // v1.12.0: tributação do produto (JSON)
        try {
            self::pdo()->query('SELECT fiscal FROM products LIMIT 1');
        } catch (PDOException $e) {
            self::pdo()->exec('ALTER TABLE products ADD COLUMN fiscal TEXT NULL');
        }
        // v1.11.0: localização do produto no almoxarifado
        try {
            self::pdo()->query('SELECT location FROM products LIMIT 1');
        } catch (PDOException $e) {
            self::pdo()->exec('ALTER TABLE products ADD COLUMN location VARCHAR(80) NULL');
        }
        // v1.10.0: produção, ficha técnica e insumos removidos. Insumos viram produtos comuns e
        // "feito na hora" passa a ter estoque próprio. (Tabela recipe_items e colunas recipe_* ficam só como histórico.)
        if ((int)self::val("SELECT COUNT(*) FROM products WHERE type = 'insumo' OR recipe_mode = 'sob_demanda'")) {
            self::run("UPDATE products SET type = 'produto' WHERE type = 'insumo'");
            self::run("UPDATE products SET recipe_mode = 'estoque' WHERE recipe_mode = 'sob_demanda'");
        }
        // v1.8.2: exclusão de produtos/serviços (os já usados ficam guardados para o histórico)
        try {
            self::pdo()->query('SELECT deleted_at FROM products LIMIT 1');
        } catch (PDOException $e) {
            self::pdo()->exec('ALTER TABLE products ADD COLUMN deleted_at ' . (self::$driver === 'mysql' ? 'DATETIME' : 'TEXT') . ' NULL');
        }
        // v1.8.1: contas a pagar ligadas à nota de compra
        try {
            self::pdo()->query('SELECT invoice_id FROM financial_entries LIMIT 1');
        } catch (PDOException $e) {
            $fk = self::$driver === 'mysql' ? 'INT UNSIGNED' : 'INTEGER';
            self::pdo()->exec("ALTER TABLE financial_entries ADD COLUMN invoice_id $fk NULL");
            try { self::pdo()->exec('CREATE INDEX idx_fin_invoice ON financial_entries (invoice_id)'); } catch (PDOException $e2) { /* já existe */ }
        }
        // Venda direta com custos adicionais descritos (JSON [{description, amount}]; o total fica em surcharge)
        try {
            self::pdo()->query('SELECT extras FROM sales LIMIT 1');
        } catch (PDOException $e) {
            self::pdo()->exec('ALTER TABLE sales ADD COLUMN extras TEXT NULL');
        }
        // Observações da venda maiores (até 2000 caracteres; o SQLite não limita o VARCHAR)
        if (self::$driver === 'mysql' && strtolower((string)self::val("SELECT DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'sales' AND COLUMN_NAME = 'note'")) === 'varchar') {
            self::pdo()->exec('ALTER TABLE sales MODIFY note TEXT NULL');
        }
        // Foto de perfil dos usuários (data URL; o navegador já manda reduzida)
        try {
            self::pdo()->query('SELECT avatar FROM users LIMIT 1');
        } catch (PDOException $e) {
            self::pdo()->exec('ALTER TABLE users ADD COLUMN avatar ' . (self::$driver === 'mysql' ? 'MEDIUMTEXT' : 'TEXT') . ' NULL');
        }
        // v3.4.0: acesso à API por token (aplicativo Android)
        try {
            self::pdo()->query('SELECT id FROM api_tokens LIMIT 1');
        } catch (PDOException $e) {
            foreach (self::schema() as $sql) {
                if (str_contains($sql, ' api_tokens ')) {
                    try { self::pdo()->exec($sql); } catch (PDOException $e2) { /* índice já existe */ }
                }
            }
        }
    }

    public static function migrate(): void
    {
        foreach (self::schema() as $sql) {
            try {
                self::pdo()->exec($sql);
            } catch (PDOException $e) {
                // Índice já existente: ignorar
                if (preg_match('/^CREATE (UNIQUE )?INDEX/i', $sql)) continue;
                throw $e;
            }
        }
    }
}

function now(): string { return date('Y-m-d H:i:s'); }
