<?php
/**
 * Testes automatizados da lógica. Rodar: php test/run.php
 * Usa um banco SQLite temporário (não mexe no banco real).
 */

require dirname(__DIR__) . '/app/bootstrap.php';

$fails = 0; $passes = 0;
function ok($cond, string $msg) {
    global $fails, $passes;
    if ($cond) { $passes++; echo "  ✓ $msg\n"; }
    else { $fails++; echo "  ✗ FALHOU: $msg\n"; }
}
function near($a, $b, string $msg, float $tol = 0.01) { ok(abs((float)$a - (float)$b) <= $tol, "$msg (obtido " . round((float)$a, 6) . ", esperado $b)"); }
function throws(callable $fn, string $contains, string $msg) {
    try { $fn(); ok(false, "$msg — deveria dar erro"); }
    catch (AppError $e) { ok(str_contains($e->getMessage(), $contains), "$msg → \"{$e->getMessage()}\""); }
}

$db = tempnam(sys_get_temp_dir(), 'est');
DB::connect(['driver' => 'sqlite', 'path' => $db]);
DB::migrate();
DB::migrate(); // rodar duas vezes não pode quebrar
PaymentMethods::seed();
PaymentTerms::seed();
QuoteStatuses::seed();
$cf = Customers::ensureDefault();
$uid = Auth::createUser(['name' => 'Pedro', 'email' => 'pedro@example.com', 'password' => 'senha12345', 'role' => 'admin']);
$fx = __DIR__ . '/fixtures';

echo "Produtos e custo médio\n";
$farinha = Inventory::saveProduct(['name' => 'Farinha de trigo', 'type' => 'produto', 'unit' => 'kg', 'min_stock' => 5]);
$acucar = Inventory::saveProduct(['name' => 'Açúcar', 'type' => 'produto', 'unit' => 'kg']);
ok(Inventory::product($acucar)['type'] === 'produto' && Inventory::product(Inventory::saveProduct(['name' => 'Antigo insumo', 'type' => 'insumo']))['type'] === 'produto', 'tipo insumo não existe mais: vira produto');
Inventory::manualMove(['product_id' => $farinha, 'kind' => 'entrada', 'quantity' => 10, 'unit_cost' => '4,00'], $uid);
Inventory::manualMove(['product_id' => $farinha, 'kind' => 'entrada', 'quantity' => 10, 'total_cost' => '60,00'], $uid);
$p = Inventory::product($farinha);
near($p['stock'], 20, 'estoque após 2 entradas');
near($p['avg_cost'], 5.0, 'custo médio ponderado (4 e 6 → 5)');
Inventory::manualMove(['product_id' => $acucar, 'kind' => 'entrada', 'quantity' => 5, 'unit_cost' => 3], $uid);
throws(fn() => Inventory::manualMove(['product_id' => $acucar, 'kind' => 'saida', 'quantity' => 6], $uid), 'Estoque insuficiente', 'bloqueia saída maior que o estoque');
Inventory::manualMove(['product_id' => $acucar, 'kind' => 'perda', 'quantity' => 1], $uid);
near(Inventory::product($acucar)['stock'], 4, 'perda baixa o estoque');
Inventory::manualMove(['product_id' => $acucar, 'kind' => 'ajuste', 'counted' => '4,5'], $uid);
near(Inventory::product($acucar)['stock'], 4.5, 'ajuste de inventário para a contagem');
near(Inventory::product($acucar)['avg_cost'], 3, 'ajuste não muda o custo médio');
throws(fn() => Inventory::saveProduct(['name' => '']), 'nome', 'exige nome do produto');
$loc = Inventory::saveProduct(['name' => 'Parafuso M8', 'type' => 'produto', 'location' => '  Corredor 2 · Prateleira A3 ']);
ok(Inventory::product($loc)['location'] === 'Corredor 2 · Prateleira A3', 'grava a localização do produto');
Inventory::saveProduct(['name' => 'Parafuso M8', 'type' => 'produto', 'active' => 1], $loc);
ok(Inventory::product($loc)['location'] === 'Corredor 2 · Prateleira A3', 'salvar sem o campo mantém a localização');
Inventory::saveProduct(['name' => 'Parafuso M8', 'type' => 'produto', 'location' => ''], $loc);
ok(Inventory::product($loc)['location'] === null, 'localização em branco limpa o campo');
ok(Inventory::product(Inventory::saveProduct(['name' => 'Mão de obra', 'type' => 'servico', 'location' => 'X']))['location'] === null, 'serviço não tem localização');

echo "Produto de revenda\n";
$donut = Inventory::saveProduct(['name' => 'Caixa mini donuts 12un', 'type' => 'produto', 'unit' => 'cx', 'sale_price' => '25,00']);
Inventory::manualMove(['product_id' => $donut, 'kind' => 'entrada', 'quantity' => 4, 'unit_cost' => '3,25'], $uid);
Inventory::manualMove(['product_id' => $farinha, 'kind' => 'saida', 'quantity' => 2], $uid);
Inventory::manualMove(['product_id' => $acucar, 'kind' => 'saida', 'quantity' => 1], $uid);
near(Inventory::product($donut)['avg_cost'], 3.25, 'custo médio do produto');
ok(!method_exists('Inventory', 'produce') && !method_exists('Inventory', 'recipe'), 'produção e ficha técnica removidas');
ok(!isset(Auth::PERMS['producao']), 'permissão de produção removida');

$oldIns = DB::insert('products', ['name' => 'Insumo antigo', 'type' => 'insumo', 'unit' => 'kg', 'recipe_mode' => 'sob_demanda', 'created_at' => now(), 'updated_at' => now()]);
DB::upgrade();
$oi = Inventory::product($oldIns);
ok($oi['type'] === 'produto' && $oi['recipe_mode'] === 'estoque', 'atualização converte insumos e "feito na hora" antigos');
DB::run('DELETE FROM products WHERE id = ?', [$oldIns]);

echo "Vendas\n";
$sale = Inventory::createSale(['customer_id' => $cf, 'payment_method' => 'pix', 'discount' => '5,00',
    'items' => [['product_id' => $donut, 'quantity' => 2]]], $uid);
$s = DB::one('SELECT * FROM sales WHERE id = ?', [$sale]);
near($s['total'], 45, 'total com desconto (2 x 25 - 5)');
near($s['cost_total'], 6.5, 'custo da venda');
near(Inventory::product($donut)['stock'], 2, 'venda baixa o produto acabado');
throws(fn() => Inventory::createSale(['customer_id' => $cf, 'payment_method' => 'xyz', 'items' => [['product_id' => $donut, 'quantity' => 1]]], $uid), 'pagamento', 'exige forma de pagamento');
throws(fn() => Inventory::createSale(['customer_id' => $cf, 'payment_method' => 'pix', 'discount' => 999, 'items' => [['product_id' => $donut, 'quantity' => 1]]], $uid), 'desconto', 'desconto maior que a venda');

$sale2 = Inventory::createSale(['customer_id' => $cf, 'payment_method' => 'dinheiro', 'items' => [['product_id' => $farinha, 'quantity' => 1, 'unit_price' => '25,00']]], $uid);
near(Inventory::product($farinha)['stock'], 17, 'venda baixa o estoque do produto');
near(DB::val('SELECT total FROM sales WHERE id = ?', [$sale2]), 25, 'preço editado na venda');
near(DB::val('SELECT cost_total FROM sales WHERE id = ?', [$sale2]), 5, 'custo da venda pelo custo médio (1kg x 5)');

Inventory::cancelSale($sale, $uid);
near(Inventory::product($donut)['stock'], 4, 'cancelamento devolve ao estoque');
near(Inventory::product($donut)['avg_cost'], 3.25, 'cancelamento mantém o custo médio');
throws(fn() => Inventory::cancelSale($sale, $uid), 'já foi cancelada', 'não cancela duas vezes');


echo "Preço automático (markup)\n";
$rev = Inventory::saveProduct(['name' => 'Refrigerante lata', 'type' => 'produto', 'markup' => '100', 'avg_cost' => '3,00']);
near(Inventory::product($rev)['sale_price'], 6, 'preço = custo x (1 + 100%)');
Inventory::manualMove(['product_id' => $rev, 'kind' => 'entrada', 'quantity' => 10, 'unit_cost' => 4], $uid);
near(Inventory::product($rev)['sale_price'], 8, 'preço acompanha o novo custo médio');
Inventory::saveProduct(['name' => 'Refrigerante lata', 'type' => 'produto', 'markup' => '', 'sale_price' => '7,50'], $rev);
Inventory::manualMove(['product_id' => $rev, 'kind' => 'entrada', 'quantity' => 10, 'unit_cost' => 10], $uid);
near(Inventory::product($rev)['sale_price'], 7.5, 'sem markup o preço fica manual');
$semCusto = Inventory::saveProduct(['name' => 'Sem custo', 'type' => 'produto', 'markup' => 80, 'sale_price' => 9]);
near(Inventory::product($semCusto)['sale_price'], 9, 'sem custo conhecido mantém o preço digitado');


echo "Clientes\n";
ok(Customers::validCnpj('00.000.000/0001-91') && Customers::validCnpj('11222333000181'), 'CNPJ válido');
ok(!Customers::validCnpj('11222333000182') && !Customers::validCnpj('11111111111111'), 'CNPJ inválido');
ok(Customers::validCpf('529.982.247-25') && !Customers::validCpf('529.982.247-24') && !Customers::validCpf('00000000000'), 'CPF');
$bb = Customers::fromBrasilApi(['razao_social' => 'PADARIA DO JOAO LTDA', 'nome_fantasia' => 'PADARIA BOM PAO', 'ddd_telefone_1' => '1133334444',
    'cep' => '01001000', 'descricao_tipo_de_logradouro' => 'PRACA', 'logradouro' => 'DA SE', 'numero' => '100', 'complemento' => '',
    'bairro' => 'SE', 'municipio' => 'SAO PAULO', 'uf' => 'SP', 'email' => 'CONTATO@PADARIA.COM', 'descricao_situacao_cadastral' => 'ATIVA']);
ok($bb['name'] === 'Padaria do Joao Ltda' && $bb['trade_name'] === 'Padaria Bom Pao', 'BrasilAPI: razão social e fantasia formatadas');
ok($bb['phone'] === '(11) 3333-4444' && $bb['street'] === 'Praca da Se' && $bb['city'] === 'Sao Paulo' && $bb['state'] === 'SP', 'BrasilAPI: telefone e endereço');
ok($bb['email'] === 'contato@padaria.com' && $bb['complement'] === null, 'BrasilAPI: e-mail e campos vazios');
$ws = Customers::fromCnpjWs(['razao_social' => 'BANCO DO BRASIL SA', 'estabelecimento' => ['nome_fantasia' => 'DIRECAO GERAL',
    'tipo_logradouro' => 'QUADRA', 'logradouro' => 'SAUN QUADRA 5 BLOCO B', 'numero' => 'SN', 'bairro' => 'ASA NORTE', 'cep' => '70040912',
    'ddd1' => '61', 'telefone1' => '34939002', 'email' => 'secex@bb.com.br', 'situacao_cadastral' => 'Ativa',
    'cidade' => ['nome' => 'Brasília'], 'estado' => ['sigla' => 'DF'],
    'inscricoes_estaduais' => [['inscricao_estadual' => '0809427800174', 'ativo' => true]]]]);
ok($ws['name'] === 'Banco do Brasil SA' && $ws['city'] === 'Brasília' && $ws['state'] === 'DF', 'CNPJ.ws: nome e cidade');
ok($ws['phone'] === '(61) 3493-9002' && $ws['state_registration'] === '0809427800174', 'CNPJ.ws: telefone e inscrição estadual');
$rw = Customers::fromReceitaWs(['nome' => 'MERCADO X ME', 'fantasia' => '', 'telefone' => '(11) 2222-3333/ (11) 9999-0000', 'cep' => '01.001-000',
    'logradouro' => 'RUA A', 'numero' => '1', 'bairro' => 'CENTRO', 'municipio' => 'CAMPINAS', 'uf' => 'SP', 'situacao' => 'ATIVA']);
ok($rw['name'] === 'Mercado X ME' && $rw['cep'] === '01001000' && $rw['phone'] === '(11) 2222-3333', 'ReceitaWS: nome, CEP e 1º telefone');
ok(Customers::fromReceitaWs(['status' => 'ERROR', 'message' => 'CNPJ inválido']) === null, 'ReceitaWS: erro vira null');
throws(fn() => Customers::lookupCnpj('11222333000182'), 'inválido', 'consulta recusa CNPJ inválido sem chamar a internet');

$cli = Customers::save(['type' => 'pj', 'document' => '11.222.333/0001-81', 'name' => 'Padaria Teste Ltda', 'trade_name' => 'Padaria Teste', 'cep' => '01001-000', 'state' => 'sp']);
$c = Customers::get($cli);
ok($c['document'] === '11222333000181' && $c['cep'] === '01001000' && $c['state'] === 'SP', 'salva documento e CEP só com números');
throws(fn() => Customers::save(['type' => 'pj', 'document' => '11222333000181', 'name' => 'Outra']), 'Já existe', 'não duplica CNPJ');
throws(fn() => Customers::save(['type' => 'pf', 'document' => '123', 'name' => 'Fulano']), 'CPF inválido', 'valida CPF');
throws(fn() => Customers::save(['type' => 'pj', 'name' => '']), 'razão social', 'exige nome');
throws(fn() => Customers::save(['type' => 'pf', 'name' => 'A', 'email' => 'x@']), 'E-mail', 'valida e-mail');
$pf = Customers::save(['type' => 'pf', 'name' => 'Maria Souza']);
ok($pf > 0, 'cliente pessoa física sem CPF');
ok(Customers::ensureDefault() === $cf, 'Consumidor final não é duplicado');
throws(fn() => Inventory::createSale(['payment_method' => 'pix', 'items' => [['product_id' => $donut, 'quantity' => 1]]], $uid), 'cliente', 'venda exige cliente');
$sc = Inventory::createSale(['customer_id' => $cli, 'payment_method' => 'pix', 'items' => [['product_id' => $donut, 'quantity' => 1]]], $uid);
$row = DB::one('SELECT customer_id, customer FROM sales WHERE id = ?', [$sc]);
ok((int)$row['customer_id'] === $cli && $row['customer'] === 'Padaria Teste', 'venda guarda o cliente (nome fantasia)');
Customers::save(['type' => 'pj', 'document' => '11222333000181', 'name' => 'Padaria Teste Ltda', 'active' => false], $cli);
throws(fn() => Inventory::createSale(['customer_id' => $cli, 'payment_method' => 'pix', 'items' => [['product_id' => $donut, 'quantity' => 1]]], $uid), 'inativo', 'não vende para cliente inativo');
Inventory::cancelSale($sc, $uid);


echo "Orçamentos\n";
$serv = Inventory::saveProduct(['name' => 'Instalação', 'type' => 'servico', 'unit' => 'h', 'sale_price' => '80,00']);
$estAntes = (float)Inventory::product($donut)['stock'];
$qid = Quotes::save([
    'target' => 'cliente', 'customer_id' => $cli2 = Customers::save(['type' => 'pf', 'name' => 'Cliente Orçamento']),
    'status' => 'rascunho', 'request_date' => date('Y-m-d'), 'expires_at' => date('Y-m-d', strtotime('+10 days')),
    'items' => [
        ['product_id' => $donut, 'quantity' => 2, 'unit_price' => '25,00', 'discount' => '5,00'],
        ['product_id' => $serv, 'quantity' => '1,5', 'unit_price' => 80],
    ],
    'extras' => [['description' => 'Frete', 'amount' => '15,00'], ['description' => '', 'amount' => '']],
    'discount_type' => 'percent', 'discount_value' => 10,
    'payment_methods' => ['pix', 'credito', 'xpto'], 'payment_terms' => '30/60 dias',
    'notes' => 'Entrega em 5 dias', 'internal_notes' => 'Cliente indicado',
], $uid);
$q = Quotes::get($qid);
near($q['products_total'], 45, 'produtos com desconto no item (2x25 - 5)');
$qp = Quotes::save(['target' => 'cliente', 'customer_id' => $cli2, 'items' => [
    ['product_id' => $serv, 'quantity' => 2, 'unit_price' => 100, 'discount_type' => 'percent', 'discount_value' => 15]]], $uid);
$qpi = Quotes::get($qp)['items'][0];
near($qpi['discount'], 30, 'desconto do item em % (15% de 200)');
ok($qpi['discount_type'] === 'percent' && (float)$qpi['discount_value'] === 15.0, 'guarda o tipo do desconto do item');
throws(fn() => Quotes::save(['target' => 'cliente', 'customer_id' => $cli2, 'items' => [['product_id' => $serv, 'quantity' => 1, 'discount_type' => 'percent', 'discount_value' => 120]]], $uid), '100%', 'desconto do item até 100%');
near($q['services_total'], 120, 'serviços (1,5h x 80)');
near($q['extras_total'], 15, 'custos adicionais (linha vazia ignorada)');
near($q['discount_total'], 18, 'desconto geral de 10% sobre 180');
near($q['total'], 162, 'total do orçamento');
ok($q['payment_methods'] === 'pix,credito' && count($q['items']) === 2 && count($q['extras']) === 1, 'formas de pagamento filtradas, itens e custos gravados');
ok(strlen($q['public_token']) === 32 && $q['status_changed_at'], 'gera link público');
near(Inventory::product($donut)['stock'], $estAntes, 'orçamento não mexe no estoque');
throws(fn() => Quotes::save(['target' => 'cliente', 'customer_id' => $cli2, 'status' => 'enviado', 'items' => []], $uid), 'pelo menos um', 'não envia orçamento vazio');
throws(fn() => Quotes::save(['target' => 'lead', 'lead_name' => ''], $uid), 'lead', 'lead exige nome');
throws(fn() => Quotes::save(['target' => 'cliente', 'customer_id' => $cli2, 'request_date' => '2026-11-10', 'expires_at' => '2026-11-01'], $uid), 'expiração', 'valida datas');
throws(fn() => Quotes::save(['target' => 'cliente', 'customer_id' => $cli2, 'discount_type' => 'valor', 'discount_value' => 999,
    'items' => [['product_id' => $serv, 'quantity' => 1]]], $uid), 'desconto', 'desconto maior que o orçamento');
throws(fn() => Quotes::convert($qid, 'pix', $uid), 'aprovados', 'só converte aprovado');

Quotes::setStatus($qid, 'enviado');
ok(Quotes::get($qid)['sent_at'] !== null, 'marca data de envio');
$sum = Quotes::summary();
ok($sum['aguardando_aprovacao'] === 1, 'indicador: aguardando aprovação');
$r = Quotes::clientRespond($q['public_token'], 'aprovar', '');
ok($r['status'] === 'aprovado' && $r['approved_at'], 'cliente aprova pelo link');
throws(fn() => Quotes::clientRespond($q['public_token'], 'recusar', 'caro'), 'já foi', 'não responde duas vezes');
ok(Quotes::summary()['aprovados_mes'] === 1, 'indicador: aprovados no mês');

$saleQ = Quotes::convert($qid, 'pix', $uid);
$sale = DB::one('SELECT * FROM sales WHERE id = ?', [$saleQ]);
near($sale['total'], 162, 'venda com o mesmo total do orçamento');
near($sale['surcharge'], 15, 'custos adicionais viram acréscimo');
near($sale['discount'], 23, 'descontos (itens + geral) viram desconto da venda');
ok((int)$sale['quote_id'] === $qid && str_contains($sale['note'], 'Orçamento #' . $qid), 'venda ligada ao orçamento');
near(Inventory::product($donut)['stock'], $estAntes - 2, 'venda baixa o estoque do produto');
ok(!DB::val("SELECT COUNT(*) FROM movements WHERE product_id = ?", [$serv]), 'serviço não gera movimento de estoque');
ok(Quotes::get($qid)['status'] === 'vendido', 'orçamento fica como vendido');
throws(fn() => Quotes::save(['target' => 'cliente', 'customer_id' => $cli2], $uid, $qid), 'virou venda', 'vendido não pode ser editado');
throws(fn() => Quotes::convert($qid, 'pix', $uid), 'já virou', 'não converte duas vezes');

$lq = Quotes::save(['target' => 'lead', 'lead_name' => 'Fulano Lead', 'lead_phone' => '11999998888', 'status' => 'enviado',
    'items' => [['product_id' => $serv, 'quantity' => 1]]], $uid);
DB::update('quotes', $lq, ['status' => 'aprovado']); // simula orçamento antigo de lead já aprovado
throws(fn() => Quotes::convert($lq, 'pix', $uid), 'lead', 'lead precisa virar cliente antes da venda');
$dup = Quotes::duplicate($qid, $uid);
$d = Quotes::get($dup);
ok($d['status'] === 'rascunho' && count($d['items']) === 2 && $d['public_token'] !== $q['public_token'], 'duplicar cria rascunho com os mesmos itens');
near($d['total'], 162, 'duplicado mantém valores');
DB::update('quotes', $dup, ['expires_at' => '2020-01-01', 'status' => 'enviado']);
throws(fn() => Quotes::clientRespond($d['public_token'], 'aprovar', ''), 'venceu', 'link não aprova orçamento vencido');
throws(fn() => Quotes::byToken('abc'), 'não encontrado', 'token inválido');
Settings::save(['company_name' => 'Minha Empresa', 'company_document' => '11.222.333/0001-81', 'quote_validity_days' => '15', 'xxx' => 'ignorado']);
ok(Settings::get('company_document') === '11222333000181' && Settings::get('quote_validity_days') === '15', 'configurações da empresa');
Settings::save(['company_cep' => '01001-000', 'company_street' => 'Praça da Sé', 'company_number' => '100', 'company_complement' => '',
    'company_district' => 'Sé', 'company_city' => 'São Paulo', 'company_state' => 'sp']);
ok(Settings::get('company_address') === 'Praça da Sé, 100 · Sé · São Paulo/SP · CEP 01001-000' && Settings::get('company_cep') === '01001000', 'monta o endereço a partir dos campos');
$png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
Settings::save(['company_logo' => $png]);
ok(Settings::get('company_logo') === $png, 'guarda o logo');
throws(fn() => Settings::save(['company_logo' => 'data:image/svg+xml;base64,PHN2Zz4=']), 'PNG', 'recusa SVG');
throws(fn() => Settings::save(['company_logo' => 'data:image/png;base64,' . base64_encode('não é imagem')]), 'inválido', 'recusa arquivo que não é imagem');
throws(fn() => Settings::save(['company_logo' => 'data:image/png;base64,' . base64_encode(str_repeat('a', 700 * 1024))]), 'grande', 'limita o tamanho do logo');
Settings::save(['company_logo' => '']);
ok(Settings::get('company_logo') === '', 'remove o logo');
Settings::save(['company_logo' => $png]);
Inventory::cancelSale($saleQ, $uid);


echo "Fornecedores\n";
$f1 = Customers::save(['type' => 'pj', 'document' => '11.444.777/0001-61', 'name' => 'Atacado Doce Ltda'], null, 'suppliers');
ok(Customers::get($f1, 'suppliers')['name'] === 'Atacado Doce Ltda', 'cadastra fornecedor');
throws(fn() => Customers::save(['type' => 'pj', 'document' => '11444777000161', 'name' => 'Outro'], null, 'suppliers'), 'fornecedor', 'não duplica CNPJ de fornecedor');
ok(Customers::save(['type' => 'pj', 'document' => '11444777000161', 'name' => 'Mesmo CNPJ como cliente']) > 0, 'mesmo CNPJ pode ser cliente e fornecedor');
throws(fn() => Customers::get(1, 'users'), 'inválido', 'só aceita clientes e fornecedores');
ok(Customers::supplierFor('11444777000161', 'Qualquer') === $f1, 'nota encontra fornecedor pelo CNPJ');
$novoF = Customers::supplierFor('12345678000195', 'DISTRIBUIDORA NOVA');
ok($novoF > 0 && Customers::get($novoF, 'suppliers')['name'] === 'DISTRIBUIDORA NOVA', 'nota cadastra fornecedor novo');
ok(Customers::supplierFor(null, 'Mercado da Esquina') === Customers::supplierFor('', 'mercado da esquina'), 'sem CNPJ: encontra pelo nome');
ok(Customers::supplierFor('', '') === null, 'sem dados: sem fornecedor');
$invF = Invoices::confirm(['kind' => 'manual', 'supplier_name' => 'Atacado Doce', 'supplier_cnpj' => '11444777000161', 'number' => '55',
    'items' => [['import' => true, 'product_id' => $farinha, 'qty' => 1, 'factor' => 1, 'cost_total' => 5]]], $uid);
ok((int)DB::val('SELECT supplier_id FROM invoices WHERE id = ?', [$invF]) === $f1, 'nota importada fica ligada ao fornecedor');
Invoices::revert($invF, $uid);

echo "Permissões\n";
$mid = Auth::createUser(['name' => 'Sócio', 'email' => 'socio@example.com', 'password' => 'senha12345', 'permissions' => ['vendas', 'clientes', 'xyz']]);
$mu = DB::one('SELECT * FROM users WHERE id = ?', [$mid]);
ok(Auth::permsOf($mu) === ['vendas', 'clientes'], 'grava só permissões conhecidas');
ok(count(Auth::permsOf(DB::one('SELECT * FROM users WHERE id = ?', [$uid]))) === count(Auth::PERMS), 'administrador tem todas');
ok(count(Auth::permsOf(['role' => 'membro', 'permissions' => null])) === count(Auth::PERMS), 'usuário antigo (sem configuração) mantém tudo');
ok(Auth::permsOf(['role' => 'membro', 'permissions' => '']) === [], 'pode ficar sem nenhuma permissão');
echo "Perfis de permissão\n";
DB::migrate(); Auth::seedProfiles();
$profs = array_column(Auth::profiles(), null, 'name');
ok(isset($profs['Acesso total'], $profs['Vendedor'], $profs['Estoque']), 'cria perfis de exemplo');
$pid = Auth::saveProfile(['name' => 'Caixa', 'permissions' => ['vendas', 'clientes', 'inexistente']]);
ok(array_column(Auth::profiles(), 'permissions', 'name')['Caixa'] === ['vendas', 'clientes'], 'perfil personalizado guarda só áreas válidas');
throws(fn() => Auth::saveProfile(['name' => 'caixa']), 'Já existe', 'não repete nome de perfil');
throws(fn() => Auth::saveProfile(['name' => ' ']), 'nome', 'perfil exige nome');
$cx = Auth::createUser(['name' => 'Ana Caixa', 'email' => 'ana@example.com', 'password' => 'senha12345', 'profile_id' => $pid]);
$getU = fn($id) => DB::one('SELECT u.*, p.permissions profile_permissions FROM users u LEFT JOIN permission_profiles p ON p.id = u.profile_id WHERE u.id = ?', [$id]);
ok(Auth::permsOf($getU($cx)) === ['vendas', 'clientes'], 'usuário recebe as permissões do perfil');
Auth::saveProfile(['id' => $pid, 'name' => 'Caixa', 'permissions' => ['vendas']]);
ok(Auth::permsOf($getU($cx)) === ['vendas'], 'mudar o perfil muda todos os usuários dele');
throws(fn() => Auth::deleteProfile($pid), 'em uso', 'não exclui perfil em uso');
DB::update('users', $cx, ['profile_id' => $profs['Estoque']['id']]);
Auth::deleteProfile($pid);
ok(!DB::val('SELECT id FROM permission_profiles WHERE id = ?', [$pid]), 'exclui perfil sem usuários');
ok(in_array('movimentacoes', Auth::permsOf($getU($cx)), true) && !in_array('vendas', Auth::permsOf($getU($cx)), true), 'troca de perfil');
throws(fn() => Auth::createUser(['name' => 'X', 'email' => 'x2@example.com', 'password' => 'senha12345', 'profile_id' => 99999]), 'Perfil', 'perfil inexistente');
$serv2 = Inventory::saveProduct(['name' => 'Decoração', 'type' => 'servico', 'sale_price' => '150,00']);
$sp = Inventory::product($serv2);
ok($sp['type'] === 'servico' && (float)$sp['sale_price'] === 150.0 && (float)$sp['stock'] === 0.0, 'serviço só com nome e preço');

echo "Relatório\n";
$rep = Reports::summary(date('Y-m-01'), date('Y-m-d'));
near($rep['revenue'], 25, 'faturamento ignora venda cancelada');
near($rep['gross_profit'], 20, 'lucro bruto');
near($rep['margin'], 80, 'margem %', 0.1);
near($rep['losses'], 3, 'perdas no período (1kg açúcar x R$3)');
ok(count($rep['by_day']) >= 1, 'série por dia');
near($rep['by_payment'][0]['total'], 25, 'por forma de pagamento');
near($rep['by_product'][0]['profit'], 20, 'lucro por produto');

echo "Só notas emitidas para o CNPJ da empresa\n";
Settings::save(['company_document' => '']);
$rx = Invoices::suggest(Invoices::parseXml(file_get_contents("$fx/nfe.xml")));
ok($rx['recipient_doc'] === '13789718000150', 'lê o CNPJ do destinatário no XML');
ok(str_contains((string)$rx['recipient_error'], 'cadastre o CNPJ da empresa'), 'sem CNPJ da empresa cadastrado: bloqueia e explica');
Settings::save(['company_document' => '11.222.333/0001-81']);
$rx = Invoices::suggest(Invoices::parseXml(file_get_contents("$fx/nfe.xml")));
ok(str_contains((string)$rx['recipient_error'], 'emitida para 13.789.718/0001-50'), 'nota para outro CNPJ: bloqueia mostrando para quem foi emitida');
throws(fn() => Invoices::confirm(['kind' => 'nfe', 'recipient_doc' => '13789718000150', 'items' => [['import' => 1, 'product_id' => $farinha, 'qty' => 1, 'cost_total' => 1]]], $uid), 'que não é o CNPJ da empresa', 'confirmação também bloqueia no servidor');
throws(fn() => Invoices::confirm(['kind' => 'nfe', 'items' => [['import' => 1, 'product_id' => $farinha, 'qty' => 1, 'cost_total' => 1]]], $uid), 'destinatário', 'sem destinatário: bloqueia');
ok(Invoices::recipientError(['kind' => 'nfce']) !== null && str_contains(Invoices::recipientError(['kind' => 'nfce']), 'cupom'), 'cupom sem CNPJ do comprador: bloqueia');
ok(Invoices::recipientError(['kind' => 'manual']) === null, 'lançamento manual (sem nota) continua liberado');
$dt = Invoices::parseDanfeText("DANFE\nEMITENTE 98.765.432/0001-10\nDESTINATÁRIO / REMETENTE\nNOME / RAZÃO SOCIAL SS COMPRESSORES CNPJ / CPF 13.789.718/0001-50 DATA DA EMISSÃO 03/11/2026");
ok($dt['recipient_doc'] === '13789718000150', 'lê o destinatário no DANFE (não o emitente)');
$nc = Invoices::parseNfceHtml(str_replace('</body>', '<div>CONSUMIDOR - CNPJ: 13.789.718/0001-50</div></body>', file_get_contents("$fx/nfce.html")));
ok($nc['recipient_doc'] === '13789718000150', 'lê o CNPJ do consumidor no cupom');
// Filiais
Settings::save(['company_branches' => [['cnpj' => '13.789.718/0001-50', 'name' => 'Filial BH'], ['cnpj' => '', 'name' => '']]]);
ok(count(Settings::companyDocs()) === 2, 'grava a filial (ignora linha vazia)');
ok(Invoices::suggest(Invoices::parseXml(file_get_contents("$fx/nfe.xml")))['recipient_error'] === null, 'nota para o CNPJ de filial cadastrada: liberada');
Settings::save(['company_branches' => []]);
ok(str_contains((string)Invoices::suggest(Invoices::parseXml(file_get_contents("$fx/nfe.xml")))['recipient_error'], 'Se for uma filial'), 'sem a filial: bloqueia e sugere cadastrar a filial');
throws(fn() => Settings::save(['company_branches' => [['cnpj' => '11.111.111/1111-11']]]), 'inválido', 'recusa CNPJ de filial inválido');
Settings::save(['company_document' => '13.789.718/0001-50']);
ok(Invoices::suggest(Invoices::parseXml(file_get_contents("$fx/nfe.xml")))['recipient_error'] === null, 'nota para o CNPJ da empresa: liberada');

echo "NF-e (XML)\n";
$inv = Invoices::suggest(Invoices::parseXml(file_get_contents("$fx/nfe.xml")));
ok($inv['access_key'] === trim(file_get_contents("$fx/key.txt")), 'lê a chave de acesso');
ok($inv['supplier_cnpj'] === '12345678000195' && $inv['supplier_name'] === 'Doce Bom', 'lê o fornecedor');
ok($inv['number'] === '4521' && $inv['issued_at'] === '2026-10-28 14:32:10', 'lê número e data');
ok(count($inv['items']) === 2, 'lê 2 itens');
near($inv['items'][0]['cost_total'], 200, 'custo do item inclui frete');
near($inv['items'][1]['cost_total'], 120 - 6 + 4.5 + 1.5, 'custo do item com desconto, ST e IPI');
near(array_sum(array_column($inv['items'], 'cost_total')), $inv['total'], 'soma dos custos = total da nota');
ok($inv['items'][0]['ean'] === '7891234567895' && $inv['items'][1]['ean'] === null, 'código de barras (ignora SEM GTIN)');
ok($inv['items'][0]['product_id'] === null, 'sem sugestão na 1ª vez');

$payload = $inv;
$payload['items'][0] += ['import' => true];
$payload['items'][0]['product_id'] = $farinha;
$payload['items'][0]['factor'] = 25; // 1 saco = 25 kg
$payload['items'][1] += ['import' => true, 'new_product' => ['name' => 'Açúcar refinado', 'type' => 'produto', 'unit' => 'kg']];
$payload['items'][1]['factor'] = 5;
$invId = Invoices::confirm($payload, $uid);
near(Inventory::product($farinha)['stock'], 17 + 50, 'entrada da nota com conversão (2 sacos x 25 kg)');
near(Inventory::product($farinha)['avg_cost'], (17 * 5 + 200) / 67, 'custo médio após nota', 0.0001);
$novo = (int)DB::val("SELECT id FROM products WHERE name = 'Açúcar refinado'");
ok($novo > 0, 'cadastra produto novo pela nota');
near(Inventory::product($novo)['avg_cost'], 120 / 5, 'custo por kg do produto novo');
throws(fn() => Invoices::confirm($payload, $uid), 'já foi importada', 'bloqueia nota duplicada');

$again = Invoices::suggest(Invoices::parseXml(file_get_contents("$fx/nfe.xml")));
ok($again['duplicate'] === $invId, 'avisa que a nota já foi importada');
ok($again['items'][0]['product_id'] === $farinha && (float)$again['items'][0]['factor'] === 25.0 && $again['items'][0]['match'] === 'fornecedor', 'lembra o produto e o fator do fornecedor');
ok($again['items'][1]['product_id'] === $novo, 'lembra produto criado pela nota');

Invoices::revert($invId, $uid);
near(Inventory::product($farinha)['stock'], 17, 'estorno da nota devolve o estoque');
near(Inventory::product($farinha)['avg_cost'], 5, 'estorno da nota restaura o custo médio', 0.0001);
ok(!DB::val('SELECT COUNT(*) FROM invoices'), 'nota removida após estorno');
throws(fn() => Invoices::parseXml('<html>oi</html>'), 'NF-e', 'rejeita XML que não é nota');

echo "DANFE (PDF)\n";
$d = Invoices::suggest(Invoices::parseDanfePdf("$fx/danfe.pdf"));
ok($d['access_key'] === trim(file_get_contents("$fx/key2.txt")), 'lê a chave do PDF');
ok($d['supplier_cnpj'] === '98765432000110' && $d['number'] === '777' && $d['series'] === '2', 'extrai CNPJ, número e série da chave');
ok($d['supplier_name'] === 'MERCADO ATACADO SAO JOSE LTDA', 'lê o fornecedor do canhoto');
ok(count($d['items']) === 2, 'lê 2 itens do DANFE');
ok(($d['items'][0]['code'] ?? '') === '1001' && ($d['items'][0]['unit'] ?? '') === 'PCT', 'código e unidade do item');
near($d['items'][1]['qty'] ?? 0, 6, 'quantidade do item');
near(array_sum(array_column($d['items'], 'cost_total')), 92.40, 'frete distribuído no custo');
ok($d['issued_at'] === '2026-11-03 00:00:00', 'data de emissão');
ok(Invoices::validKey(trim(file_get_contents("$fx/key2.txt"))) && !Invoices::validKey(str_repeat('1', 44)), 'valida dígito da chave');

echo "NFC-e (QR Code)\n";
$n = Invoices::parseNfceHtml(file_get_contents("$fx/nfce.html"));
ok(count($n['items']) === 2, 'lê itens da página da SEFAZ');
ok($n['items'][0]['description'] === 'OLEO DE SOJA 900ML' && $n['items'][0]['code'] === '7891107101621', 'descrição e código');
near($n['items'][1]['qty'], 2, 'quantidade');
ok($n['items'][1]['unit'] === 'BDJ', 'unidade');
near($n['total'], 65, 'valor a pagar');
near(array_sum(array_column($n['items'], 'cost_total')), 65, 'desconto distribuído no custo');
ok($n['supplier_name'] === 'SUPERMERCADO BOA COMPRA LTDA' && $n['supplier_cnpj'] === '11222333000181', 'emitente');
ok($n['number'] === '98765' && $n['issued_at'] === '2026-11-02 18:22:05', 'número e emissão');
$k = trim(file_get_contents("$fx/key.txt"));
ok(Invoices::keyFromQr("https://www.nfce.fazenda.sp.gov.br/qrcode?p=$k|2|1|1|ABC") === $k, 'extrai chave do QR Code');
throws(fn() => Invoices::fetchNfce('https://exemplo.com/?p=' . $k), 'oficial', 'só consulta portais .gov.br');

echo "Usuários\n";
throws(fn() => Auth::createUser(['name' => 'X', 'email' => 'pedro@example.com', 'password' => '12345678']), 'Já existe', 'e-mail único');
throws(fn() => Auth::createUser(['name' => 'X', 'email' => 'x@example.com', 'password' => '123']), '8 caracteres', 'senha mínima');
ok(str_contains(Backup::sql(), 'INSERT INTO `products`'), 'backup gera SQL');


echo "Atualização de banco antigo (v1.2 → v1.3)\n";
$old = tempnam(sys_get_temp_dir(), 'old');
DB::connect(['driver' => 'sqlite', 'path' => $old]);
DB::migrate();
DB::pdo()->exec('DROP INDEX idx_sales_customer');
DB::pdo()->exec('ALTER TABLE sales DROP COLUMN customer_id');
DB::pdo()->exec('DROP TABLE customers');
DB::pdo()->exec("INSERT INTO sales (customer, payment_method, subtotal, total, created_at) VALUES ('Fulano', 'pix', 10, 10, '2026-01-01 10:00:00')");
DB::upgrade();
ok(DB::val('SELECT COUNT(*) FROM customers') == 1 && DB::val('SELECT name FROM customers') === 'Consumidor final', 'cria tabela de clientes e o Consumidor final');
ok(DB::val('SELECT customer FROM sales') === 'Fulano' && DB::val('SELECT customer_id FROM sales') === null, 'vendas antigas mantidas');
DB::upgrade();
ok(DB::val('SELECT COUNT(*) FROM customers') == 1, 'rodar de novo não duplica');
$rep = Reports::summary('2026-01-01', '2026-01-31');
ok(($rep['by_customer'][0]['name'] ?? '') === 'Fulano', 'relatório mostra cliente de venda antiga');
@unlink($old);


echo "Atualização de banco antigo (v1.3 → v1.4)\n";
$old2 = tempnam(sys_get_temp_dir(), 'old');
DB::connect(['driver' => 'sqlite', 'path' => $old2]);
DB::migrate();
foreach (['DROP TABLE quote_items', 'DROP TABLE quote_extras', 'DROP TABLE quotes', 'DROP TABLE settings',
    'ALTER TABLE sales DROP COLUMN quote_id', 'ALTER TABLE sales DROP COLUMN surcharge'] as $sql) DB::pdo()->exec($sql);
DB::upgrade();
ok(DB::val('SELECT COUNT(*) FROM quotes') === 0 && DB::val('SELECT COUNT(*) FROM settings') === 0, 'cria tabelas de orçamento e configurações');
ok(DB::val('SELECT COUNT(*) FROM sales WHERE surcharge = 0 AND quote_id IS NULL') === 0, 'vendas ganham acréscimo e vínculo com orçamento');
DB::upgrade();
ok(true, 'rodar de novo não quebra');
@unlink($old2);


echo "Atualização de banco antigo (v1.4 → v1.5)\n";
$old3 = tempnam(sys_get_temp_dir(), 'old');
DB::connect(['driver' => 'sqlite', 'path' => $old3]);
DB::migrate();
foreach (['DROP INDEX idx_invoices_supplier', 'ALTER TABLE invoices DROP COLUMN supplier_id', 'DROP TABLE suppliers', 'ALTER TABLE users DROP COLUMN permissions', 'ALTER TABLE users DROP COLUMN profile_id', 'DROP TABLE permission_profiles',
    'ALTER TABLE quote_items DROP COLUMN discount_type', 'ALTER TABLE quote_items DROP COLUMN discount_value'] as $sql) DB::pdo()->exec($sql);
DB::pdo()->exec("INSERT INTO users (name, email, password_hash, role, active, created_at) VALUES ('Membro', 'm@x.com', 'x', 'membro', 1, '2026-01-01 00:00:00')");
DB::pdo()->exec("INSERT INTO invoices (kind, supplier_name, supplier_cnpj, total, created_at) VALUES ('nfe', 'Fornecedor Antigo', '11444777000161', 10, '2026-01-01 00:00:00'), ('nfe', 'Fornecedor Antigo', '11444777000161', 20, '2026-01-02 00:00:00'), ('manual', 'Feira', NULL, 5, '2026-01-03 00:00:00')");
DB::upgrade();
ok((int)DB::val('SELECT COUNT(*) FROM suppliers') === 2, 'cria fornecedores a partir das notas antigas');
ok((int)DB::val('SELECT COUNT(*) FROM invoices WHERE supplier_id IS NOT NULL') === 3, 'liga as notas antigas aos fornecedores');
ok(count(Auth::permsOf(DB::one("SELECT * FROM users WHERE email = 'm@x.com'"))) === count(Auth::PERMS), 'membros existentes continuam com acesso total');
ok((int)DB::val('SELECT COUNT(*) FROM permission_profiles') === 4, 'cria os perfis de exemplo na atualização');
DB::upgrade();
ok((int)DB::val('SELECT COUNT(*) FROM suppliers') === 2, 'rodar de novo não duplica');
@unlink($old3);

echo "Financeiro\n";
DB::connect(['driver' => 'sqlite', 'path' => $db]);
DB::upgrade();
$saleCount = (int)DB::val("SELECT COUNT(*) FROM sales WHERE status = 'ok' AND total > 0");
ok((int)DB::val('SELECT COUNT(*) FROM financial_entries WHERE sale_id IS NOT NULL') === 0 || true, 'tabela financeira existe');
$prodF = Inventory::saveProduct(['name' => 'Filtro de ar', 'type' => 'produto', 'unit' => 'un', 'sale_price' => 50, 'min_stock' => 10]);
Inventory::manualMove(['product_id' => $prodF, 'kind' => 'entrada', 'quantity' => 3, 'unit_cost' => 20], $uid);
$sid = Inventory::createSale(['customer_id' => $cf, 'payment_method' => 'pix', 'items' => [['product_id' => $prodF, 'quantity' => 2]]], $uid);
$fe = DB::one('SELECT * FROM financial_entries WHERE sale_id = ?', [$sid]);
ok($fe && $fe['type'] === 'receber' && $fe['status'] === 'pago', 'venda entra no caixa como recebida');
near($fe['paid_amount'], 100, 'valor recebido da venda');
throws(fn() => Finance::delete((int)$fe['id']), 'cancele a venda', 'não exclui recebimento de venda');
Inventory::cancelSale($sid, $uid);
ok(DB::val('SELECT status FROM financial_entries WHERE sale_id = ?', [$sid]) === 'cancelado', 'cancelar a venda cancela o recebimento');

$ids = Finance::save(['type' => 'pagar', 'description' => 'Aluguel', 'category' => 'Aluguel', 'amount' => '1.500,00',
    'due_date' => '2026-01-31', 'installments' => 3, 'installment_mode' => 'repetir'], $uid);
ok(count($ids) === 3, 'repetir cria 3 lançamentos');
ok(DB::val('SELECT due_date FROM financial_entries WHERE id = ?', [$ids[1]]) === '2026-02-28', 'parcela de 31/01 vence no fim de fevereiro');
ok(DB::val('SELECT due_date FROM financial_entries WHERE id = ?', [$ids[2]]) === '2026-03-31', 'volta ao dia 31 em março');
$ids2 = Finance::save(['type' => 'receber', 'description' => 'Serviço parcelado', 'amount' => 100, 'due_date' => date('Y-m-d'), 'installments' => 3], $uid);
near(DB::val('SELECT SUM(amount) FROM financial_entries WHERE id IN (' . implode(',', $ids2) . ')'), 100, 'dividir mantém o total');
near(DB::val('SELECT amount FROM financial_entries WHERE id = ?', [$ids2[2]]), 33.34, 'centavo que sobra vai na última parcela');
throws(fn() => Finance::save(['type' => 'pagar', 'description' => 'X', 'amount' => 0, 'due_date' => '2026-01-01'], $uid), 'valor', 'exige valor');
throws(fn() => Finance::save(['type' => 'x', 'description' => 'X', 'amount' => 1, 'due_date' => '2026-01-01'], $uid), 'pagar ou a receber', 'exige tipo');

Finance::pay($ids[0], ['paid_at' => '2026-02-02', 'paid_amount' => '1000', 'keep_rest' => 1]);
$e0 = Finance::get($ids[0]);
ok($e0['status'] === 'pago', 'baixa marca como pago');
near($e0['amount'], 1000, 'pagamento parcial fica com o valor pago');
near(DB::val("SELECT amount FROM financial_entries WHERE description = 'Aluguel (restante)' AND status = 'aberto'"), 500, 'restante fica em aberto');
throws(fn() => Finance::pay($ids[0], []), 'em aberto', 'não dá baixa duas vezes');
ok(Finance::get($ids[1])['display_status'] === 'vencido', 'em aberto com vencimento passado aparece como vencido');
Finance::reopen($ids[0]);
ok(Finance::get($ids[0])['status'] === 'aberto' && Finance::get($ids[0])['paid_at'] === null, 'reabrir desfaz a baixa');
Finance::pay($ids[0], ['paid_at' => '2026-02-02']);

Finance::setOpening('2.000,00');
near(Finance::opening(), 2000, 'saldo inicial do caixa');
$cfw = Finance::cashflow(['from' => '2026-02-01', 'to' => '2026-02-28']);
near($cfw['opening'], 2000, 'saldo de abertura do período');
near($cfw['totals']['out'], 1000, 'saídas realizadas no período');
near($cfw['closing'], 1000, 'saldo final realizado');
ok(count($cfw['rows']) === 28, 'uma linha por dia');
$cfm = Finance::cashflow(['from' => '2026-01-01', 'to' => '2026-12-31', 'group' => 'mes']);
ok(count($cfm['rows']) === 12, 'agrupa por mês');
$cft = Finance::cashflow(['from' => date('Y-m-d'), 'to' => date('Y-m-d', strtotime('+70 days'))]);
near($cft['rows'][0]['in_proj'], 33.33, 'previsto de hoje inclui o que vence hoje');
near($cft['rows'][0]['out_proj'], 500 + 1500 + 1500, 'atrasados entram no previsto de hoje');
near($cft['overdue']['pagar'], 3500, 'total de atrasados a pagar');
$sum = Finance::summary();
near($sum['pagar']['overdue'], 3500, 'resumo: vencidos a pagar');
$l = Finance::list(['type' => 'pagar', 'status' => 'vencido']);
ok($l['total'] === 3, 'filtro de vencidos');
Finance::cancel($ids[2]);
throws(fn() => Finance::save(['description' => 'x', 'amount' => 1, 'due_date' => '2026-01-01'], $uid, $ids[2]), 'cancelado', 'não edita cancelado');
throws(fn() => Finance::cancel($ids[0]), 'Reabra', 'não cancela pago sem reabrir');

echo "Nota com duplicatas → contas a pagar\n";
$nx = Invoices::suggest(Invoices::parseXml(file_get_contents($fx . '/nfe-duplicatas.xml')));
ok(count($nx['installments']) === 4, 'lê as 4 duplicatas do XML');
ok($nx['installments'][3]['due_date'] === '2027-01-30', 'vencimento da última parcela');
near(array_sum(array_column($nx['installments'], 'amount')), 11071.73, 'soma das parcelas = total da nota');
ok($nx['payment_method'] === 'boleto', 'forma de pagamento: boleto (tPag 15)');
$dupItems = array_map(fn($i) => $i + ['import' => 1, 'new_product' => ['name' => $i['description'], 'type' => 'produto', 'unit' => 'un']], $nx['items']);
$inst = $nx['installments']; $inst[0]['paid'] = 1;
$nid = Invoices::confirm(['kind' => 'nfe', 'recipient_doc' => $nx['recipient_doc'], 'access_key' => $nx['access_key'], 'number' => $nx['number'], 'supplier_name' => $nx['supplier_name'],
    'supplier_cnpj' => $nx['supplier_cnpj'], 'items' => $dupItems, 'payables' => 1, 'installments' => $inst, 'payment_method' => 'boleto'], $uid);
$pays = DB::all('SELECT * FROM financial_entries WHERE invoice_id = ? ORDER BY due_date', [$nid]);
ok(count($pays) === 4 && $pays[0]['type'] === 'pagar', 'cria 4 contas a pagar');
ok($pays[0]['status'] === 'pago' && $pays[0]['paid_at'] === '2026-11-01', 'parcela marcada como paga fica paga no vencimento');
ok($pays[1]['status'] === 'aberto' && $pays[1]['payment_method'] === 'boleto' && $pays[1]['category'] === 'Fornecedores', 'demais em aberto, boleto, categoria Fornecedores');
ok($pays[2]['supplier_id'] && (int)$pays[2]['supplier_id'] === (int)DB::val('SELECT supplier_id FROM invoices WHERE id = ?', [$nid]), 'ligadas ao fornecedor da nota');
ok(str_contains($pays[3]['description'], 'NF 613799') && str_contains($pays[3]['description'], 'parcela 004'), 'descrição com número da nota e parcela');
throws(fn() => Invoices::revert($nid, $uid), 'já pagas', 'não desfaz nota com parcela paga');
Finance::reopen((int)$pays[0]['id']);
Invoices::revert($nid, $uid);
ok((int)DB::val('SELECT COUNT(*) FROM financial_entries WHERE invoice_id = ?', [$nid]) === 0, 'desfazer a nota remove as parcelas');
throws(fn() => Finance::fromInvoice(1, null, [['due_date' => '', 'amount' => 10]], 'boleto', $uid), 'vencimento', 'parcela sem vencimento');
$nid2 = Invoices::confirm(['kind' => 'manual', 'number' => '77', 'supplier_name' => 'Feira', 'items' => [['import' => 1, 'product_id' => $prodF, 'qty' => 1, 'cost_total' => 10]]], $uid);
ok((int)DB::val('SELECT COUNT(*) FROM financial_entries WHERE invoice_id = ?', [$nid2]) === 0, 'sem parcelas marcadas, não lança nada');
$dd = Invoices::danfeInstallments('FATURA / DUPLICATA Número Venc. Valor 001 01/11/2026 2.767,93 002 01/12/2026 R$ 2.767,93 CÁLCULO DO IMPOSTO BASE 05/11/2026 1.000,00');
ok(count($dd) === 2 && $dd[1]['due_date'] === '2026-12-01' && abs($dd[1]['amount'] - 2767.93) < 0.01, 'lê duplicatas do DANFE (só no quadro da fatura)');

echo "Ordens de compra\n";
$sup = (int)DB::val("SELECT id FROM suppliers WHERE document = '11444777000161'");
DB::insert('supplier_map', ['supplier_cnpj' => '11444777000161', 'supplier_code' => 'FA-900', 'product_id' => $prodF, 'factor' => 1]);
$serv = Inventory::saveProduct(['name' => 'Mão de obra', 'type' => 'servico', 'sale_price' => 100]);
throws(fn() => PurchaseOrders::save(['items' => [['product_id' => $prodF, 'quantity' => 1]]], $uid), 'fornecedor', 'exige fornecedor');
throws(fn() => PurchaseOrders::save(['supplier_id' => $sup, 'items' => []], $uid), 'pelo menos um item', 'exige itens');
throws(fn() => PurchaseOrders::save(['supplier_id' => $sup, 'items' => [['product_id' => $serv, 'quantity' => 1]]], $uid), 'serviço', 'não aceita serviço');
$stockBefore = (float)Inventory::product($prodF)['stock'];
$movBefore = (int)DB::val('SELECT COUNT(*) FROM movements');
$finBefore = (int)DB::val('SELECT COUNT(*) FROM financial_entries');
$po = PurchaseOrders::save(['supplier_id' => $sup, 'expected_date' => date('Y-m-d', strtotime('+5 days')), 'freight' => '15', 'discount' => '5',
    'payment_terms' => '30/60', 'payment_method' => 'boleto',
    'items' => [['product_id' => $prodF, 'quantity' => 10, 'unit_cost' => '19,5'], ['product_id' => $farinha, 'quantity' => 2]]], $uid);
$o = PurchaseOrders::get($po);
near($o['items_total'], 195 + 2 * 5, 'total dos itens (custo médio quando não informado)');
near($o['total'], 215, 'total com frete e desconto');
ok($o['items'][0]['supplier_code'] === 'FA-900', 'mostra o código do item no fornecedor');
ok(strlen($o['public_token']) === 32, 'gera link público');
$low = PurchaseOrders::lowStock();
$lf = array_values(array_filter($low, fn($r) => (int)$r['id'] === $prodF))[0] ?? null;
ok($lf && (float)$lf['on_order'] == 10 && (float)$lf['missing'] == 0, 'abaixo do mínimo considera o que já está pedido');
PurchaseOrders::supplierConfirm($o['public_token'], 'Entrega na quinta');
$o = PurchaseOrders::get($po);
ok($o['status'] === 'confirmada' && $o['supplier_comment'] === 'Entrega na quinta', 'fornecedor confirma pelo link');
throws(fn() => PurchaseOrders::supplierConfirm($o['public_token']), 'não pode mais', 'não confirma duas vezes');
throws(fn() => PurchaseOrders::save(['supplier_id' => $sup, 'items' => [['product_id' => $prodF, 'quantity' => 1]]], $uid, $po), 'não pode ser editada', 'confirmada não é editável');
PurchaseOrders::setStatus($po, 'recebida', '2026-03-10');
ok(PurchaseOrders::get($po)['received_at'] === '2026-03-10', 'marca como recebida');
near(Inventory::product($prodF)['stock'], $stockBefore, 'ordem de compra não mexe no estoque');
ok((int)DB::val('SELECT COUNT(*) FROM movements') === $movBefore, 'nenhuma movimentação criada');
ok((int)DB::val('SELECT COUNT(*) FROM financial_entries') === $finBefore, 'não gera contas a pagar');
throws(fn() => PurchaseOrders::delete($po), 'rascunho ou canceladas', 'não exclui recebida');
$dup = PurchaseOrders::duplicate($po, $uid);
ok(PurchaseOrders::get($dup)['status'] === 'rascunho' && count(PurchaseOrders::get($dup)['items']) === 2, 'duplicar cria rascunho com os itens');
PurchaseOrders::delete($dup);
ok(!DB::val('SELECT id FROM purchase_orders WHERE id = ?', [$dup]), 'exclui rascunho');
ok(PurchaseOrders::list(['q' => 'Filtro'])['total'] === 1, 'busca pelo item');

echo "Atualização para v1.8\n";
$old4 = tempnam(sys_get_temp_dir(), 'old');
DB::connect(['driver' => 'sqlite', 'path' => $old4]);
DB::migrate();
Auth::seedProfiles();
foreach (['DROP TABLE financial_entries', 'DROP TABLE purchase_order_items', 'DROP TABLE purchase_orders'] as $sql) DB::pdo()->exec($sql);
DB::pdo()->exec("UPDATE permission_profiles SET permissions = 'vendas,produtos' WHERE name = 'Acesso total'");
$c4 = Customers::ensureDefault();
DB::pdo()->exec("INSERT INTO sales (customer_id, customer, payment_method, subtotal, discount, surcharge, total, cost_total, status, user_id, created_at) VALUES ($c4, 'Consumidor', 'pix', 80, 0, 0, 80, 10, 'ok', NULL, '2026-01-05 10:00:00'), ($c4, 'Consumidor', 'dinheiro', 30, 0, 0, 30, 5, 'cancelada', NULL, '2026-01-06 10:00:00')");
DB::upgrade();
ok((int)DB::val("SELECT COUNT(*) FROM financial_entries WHERE status = 'pago'") === 1, 'vendas antigas entram no caixa');
ok(DB::val("SELECT paid_at FROM financial_entries") === '2026-01-05', 'com a data da venda');
ok(str_contains(DB::val("SELECT permissions FROM permission_profiles WHERE name = 'Acesso total'"), 'financeiro'), 'Acesso total ganha Financeiro');
DB::upgrade();
ok((int)DB::val('SELECT COUNT(*) FROM financial_entries') === 1, 'rodar de novo não duplica');
@unlink($old4);

$md = Reports::dashboard()['month_days'];
ok(count($md) === (int)date('t') && $md[0]['date'] === date('Y-m-01') && end($md)['date'] === date('Y-m-t'), 'gráfico do painel tem todos os dias do mês');
echo "Valor em estoque sem serviços\n";
DB::connect(['driver' => 'sqlite', 'path' => $db]);
DB::upgrade();
$sv0 = Reports::stockValue();
$svc = Inventory::saveProduct(['name' => 'Manutenção de compressor', 'type' => 'servico', 'sale_price' => 300, 'min_stock' => 5]);
DB::run('UPDATE products SET stock = 10, avg_cost = 50 WHERE id = ?', [$svc]); // serviço com estoque antigo (era produto)
$sv1 = Reports::stockValue();
near($sv1['value'], $sv0['value'], 'serviço não entra no valor em estoque');
ok($sv1['products'] === $sv0['products'] && $sv1['low'] === $sv0['low'], 'nem na contagem de produtos e de abaixo do mínimo');
ok(!in_array($svc, array_column(Reports::dashboard()['low_stock'], 'id')), 'nem na lista "precisa repor"');
Inventory::saveProduct(['name' => 'Manutenção de compressor', 'type' => 'servico', 'sale_price' => 300, 'active' => 0], $svc);

echo "Excluir produtos e serviços\n";
$tmp = Inventory::saveProduct(['name' => 'Produto cadastrado por engano', 'type' => 'produto', 'sale_price' => 10]);
ok(Inventory::deleteProduct($tmp) === 'apagado', 'nunca usado: apaga de vez');
ok(!DB::val('SELECT id FROM products WHERE id = ?', [$tmp]), 'some do banco');
$svDel = Inventory::saveProduct(['name' => 'Revisão completa', 'type' => 'servico', 'sale_price' => 450]);
$prDel = Inventory::saveProduct(['name' => 'Válvula de alívio', 'type' => 'produto', 'sale_price' => 90, 'barcode' => '7890001112223']);
$qDel = Quotes::save(['customer_id' => $cf, 'status' => 'rascunho', 'items' => [
    ['product_id' => $svDel, 'quantity' => 1, 'unit_price' => 450], ['product_id' => $prDel, 'quantity' => 2, 'unit_price' => 90]]], $uid);
ok(Inventory::deleteProduct($svDel) === 'arquivado', 'serviço usado em orçamento: guardado para o histórico');
ok(Inventory::deleteProduct($prDel) === 'arquivado', 'produto sem estoque usado em orçamento: guardado');
$qd = Quotes::get($qDel);
ok(count($qd['items']) === 2 && $qd['items'][0]['description'] === 'Revisão completa' && (float)$qd['total'] === 630.0, 'orçamento mantém os itens excluídos, descrição e valores');
ok(!empty($qd['items'][0]['product_deleted']), 'orçamento sabe que o item foi excluído');
Quotes::save(['customer_id' => $cf, 'status' => 'em_elaboracao', 'items' => array_map(fn($i) => ['product_id' => $i['product_id'], 'description' => $i['description'], 'quantity' => $i['quantity'], 'unit_price' => $i['unit_price']], $qd['items'])], $uid, $qDel);
ok(Quotes::get($qDel)['status'] === 'em_elaboracao', 'orçamento antigo continua podendo ser salvo com o item excluído');
throws(fn() => Quotes::save(['customer_id' => $cf, 'items' => [['product_id' => $svDel, 'quantity' => 1]]], $uid), 'excluído', 'não entra em orçamento novo');
throws(fn() => Inventory::createSale(['customer_id' => $cf, 'payment_method' => 'pix', 'items' => [['product_id' => $svDel, 'quantity' => 1]]], $uid), 'excluído', 'não entra em venda');
throws(fn() => Inventory::saveProduct(['name' => 'X', 'type' => 'servico'], $svDel), 'excluído', 'não pode ser editado');
throws(fn() => Inventory::deleteProduct($svDel), 'já foi excluído', 'não exclui duas vezes');
ok(DB::val('SELECT barcode FROM products WHERE id = ?', [$prDel]) === null, 'libera o código de barras');
$names = array_column(DB::all('SELECT name FROM products WHERE deleted_at IS NULL'), 'name');
ok(!in_array('Revisão completa', $names, true), 'fora das listas');
$withStock = Inventory::saveProduct(['name' => 'Correia em estoque', 'type' => 'produto', 'unit' => 'un']);
Inventory::manualMove(['product_id' => $withStock, 'kind' => 'entrada', 'quantity' => 3, 'unit_cost' => 20], $uid);
throws(fn() => Inventory::deleteProduct($withStock), 'Zere o estoque', 'produto com estoque: pede para zerar antes');
Inventory::manualMove(['product_id' => $withStock, 'kind' => 'perda', 'quantity' => 3], $uid);
ok(Inventory::deleteProduct($withStock) === 'arquivado', 'com estoque zerado e histórico: guardado');

echo "Formas de pagamento\n";
ok(count(PaymentMethods::active()) === 8 && PaymentMethods::label('pix') === 'PIX' && PaymentMethods::label('boleto') === 'Boleto', 'já vem com as formas que existiam');
$va = PaymentMethods::save(['name' => 'Vale alimentação']);
$vaCode = DB::val('SELECT code FROM payment_methods WHERE id = ?', [$va]);
ok($vaCode === 'vale_alimentacao', 'cadastra forma nova (código sem acento)');
throws(fn() => PaymentMethods::save(['name' => 'pix']), 'Já existe', 'não repete nome');
$sva = Inventory::createSale(['customer_id' => $cf, 'payment_method' => $vaCode, 'items' => [['product_id' => $prodF, 'quantity' => 1]]], $uid);
ok(DB::val('SELECT payment_method FROM sales WHERE id = ?', [$sva]) === $vaCode, 'venda com a forma nova');
ok(DB::val('SELECT payment_method FROM financial_entries WHERE sale_id = ?', [$sva]) === $vaCode, 'vai para o financeiro com a mesma forma');
throws(fn() => PaymentMethods::delete($va), 'Desative', 'forma usada não é excluída');
PaymentMethods::save(['name' => 'Vale-alimentação', 'active' => 0], $va);
ok(!isset(PaymentMethods::active()[$vaCode]) && PaymentMethods::label($vaCode) === 'Vale-alimentação', 'desativada some da lista, histórico mostra o nome novo');
throws(fn() => Inventory::createSale(['customer_id' => $cf, 'payment_method' => $vaCode, 'items' => [['product_id' => $prodF, 'quantity' => 1]]], $uid), 'desativada', 'venda nova não aceita forma desativada');
$rep = Reports::summary(date('Y-m-d'), date('Y-m-d'));
ok(in_array('Vale-alimentação', array_column($rep['by_payment'], 'label'), true), 'relatório mostra o nome da forma');
$tmpPm = PaymentMethods::save(['name' => 'Criada por engano']);
PaymentMethods::delete($tmpPm);
ok(!DB::val('SELECT id FROM payment_methods WHERE id = ?', [$tmpPm]), 'forma nunca usada é excluída');
$ids = array_column(PaymentMethods::rows(), 'id');
PaymentMethods::reorder(array_reverse($ids));
ok(PaymentMethods::rows()[0]['id'] == end($ids), 'muda a ordem');
PaymentMethods::reorder($ids);
foreach (PaymentMethods::rows() as $r) if ($r['code'] !== 'pix' && $r['active']) PaymentMethods::save(['name' => $r['name'], 'active' => 0], (int)$r['id']);
throws(fn() => PaymentMethods::save(['name' => 'PIX', 'active' => 0], (int)DB::val("SELECT id FROM payment_methods WHERE code = 'pix'")), 'pelo menos uma', 'deixa pelo menos uma ativa');
foreach (PaymentMethods::rows() as $r) if ($r['code'] !== $vaCode) PaymentMethods::save(['name' => $r['name'], 'active' => 1], (int)$r['id']);

echo "Telefone do usuário no orçamento\n";
DB::update('users', $uid, ['phone' => Auth::phone('(31) 98888-7777')]);
throws(fn() => Auth::phone('123'), 'inválido', 'recusa telefone curto demais');
ok(Auth::phone('  ') === null, 'telefone vazio fica em branco');
$qPh = Quotes::save(['customer_id' => $cf, 'status' => 'rascunho', 'items' => [['product_id' => $prodF, 'quantity' => 1, 'unit_price' => 10]]], $uid);
$gq = Quotes::get($qPh);
ok($gq['user_phone'] === '(31) 98888-7777' && $gq['user_email'] !== null && $gq['user_name'] !== null, 'orçamento traz nome, telefone e e-mail do responsável');

echo "Máscara de telefone\n";
foreach (['31998765432' => '(31) 99876-5432', '3133556953' => '(31) 3355-6953', '+55 31 99876-5432' => '(31) 99876-5432',
    '08001234567' => '0800 123 4567', '031 3355-6953' => '(31) 3355-6953', '+1 415 555 0101' => '+1 415 555 0101'] as $in => $want) {
    ok(Customers::phoneMask($in) === $want, "telefone $in → $want");
}
$cPh = Customers::save(['type' => 'pf', 'name' => 'Cliente Telefone', 'phone' => '31 3355 6953']);
ok(Customers::get($cPh)['phone'] === '(31) 3355-6953', 'cliente salvo com a máscara');
Settings::save(['company_phone' => '3133556953']);
ok(Settings::get('company_phone') === '(31) 3355-6953', 'telefone da empresa com a máscara');
ok(Auth::phone('31998765432') === '(31) 99876-5432', 'telefone do usuário com a máscara');

echo "Parcelamento por forma de pagamento\n";
$rules = PaymentMethods::rules();
ok($rules['credito']['max'] === 12 && $rules['credito']['mode'] === 'fixo' && $rules['boleto']['mode'] === 'livre' && $rules['pix']['max'] === 1, 'padrões: crédito até 12x (30 em 30), boleto livre, PIX à vista');
$crediario = PaymentMethods::save(['name' => 'Crediário', 'max_installments' => 6, 'interval_mode' => 'livre']);
$crCode = DB::val('SELECT code FROM payment_methods WHERE id = ?', [$crediario]);
ok(PaymentMethods::rules()[$crCode] === ['max' => 6, 'mode' => 'livre'], 'cadastra máximo de parcelas e intervalo');
throws(fn() => PaymentMethods::installments('pix', 2, 30), 'não aceita parcelamento', 'PIX não parcela');
throws(fn() => PaymentMethods::installments('credito', 13, 30), 'no máximo 12x', 'respeita o máximo');
ok(PaymentMethods::installments('credito', 3, 15) === [3, 30], 'intervalo fixo é sempre 30 dias');
ok(PaymentMethods::installments($crCode, 3, 15) === [3, 15], 'intervalo livre usa o escolhido');
throws(fn() => PaymentMethods::save(['name' => 'X', 'max_installments' => 0]), 'de 1 a', 'máximo de parcelas válido');
Inventory::manualMove(['product_id' => $prodF, 'kind' => 'entrada', 'quantity' => 5, 'unit_cost' => 10], $uid);
$sP = Inventory::createSale(['customer_id' => $cf, 'payment_method' => $crCode, 'installments' => 3, 'installment_days' => 15, 'items' => [['product_id' => $prodF, 'quantity' => 1, 'unit_price' => 100]]], $uid);
$parc = DB::all('SELECT * FROM financial_entries WHERE sale_id = ? ORDER BY installment', [$sP]);
$sd = substr(DB::val('SELECT created_at FROM sales WHERE id = ?', [$sP]), 0, 10);
ok(count($parc) === 3 && $parc[0]['status'] === 'aberto' && $parc[0]['type'] === 'receber', 'venda parcelada vira 3 contas a receber em aberto');
ok($parc[0]['due_date'] === date('Y-m-d', strtotime("$sd +15 days")) && $parc[2]['due_date'] === date('Y-m-d', strtotime("$sd +45 days")), 'vencimentos a cada 15 dias');
near(array_sum(array_column($parc, 'amount')), 100, 'parcelas somam o total');
near($parc[2]['amount'], 33.34, 'centavo que sobra na última');
Finance::pay((int)$parc[0]['id'], []);
Finance::reopen((int)$parc[0]['id']);
ok(Finance::get((int)$parc[0]['id'])['status'] === 'aberto', 'parcela de venda pode ser recebida e reaberta');
$qI = Quotes::save(['customer_id' => $cf, 'status' => 'aprovado', 'payment_methods' => ['credito'], 'installments' => 4, 'installment_days' => 10,
    'items' => [['product_id' => $prodF, 'quantity' => 1, 'unit_price' => 80]]], $uid);
$gI = Quotes::get($qI);
ok((int)$gI['installments'] === 4 && (int)$gI['installment_days'] === 30, 'orçamento guarda parcelas (crédito: 30 em 30)');
throws(fn() => Quotes::save(['customer_id' => $cf, 'payment_methods' => ['pix'], 'installments' => 2, 'items' => [['product_id' => $prodF, 'quantity' => 1]]], $uid), 'não aceita parcelamento', 'orçamento respeita a forma');
$sI = Quotes::convert($qI, 'credito', $uid);
ok((int)DB::val('SELECT installments FROM sales WHERE id = ?', [$sI]) === 4 && (int)DB::val('SELECT COUNT(*) FROM financial_entries WHERE sale_id = ?', [$sI]) === 4, 'venda do orçamento herda o parcelamento');
$s1 = Inventory::createSale(['customer_id' => $cf, 'payment_method' => 'credito', 'items' => [['product_id' => $prodF, 'quantity' => 1, 'unit_price' => 50]]], $uid);
ok(DB::val('SELECT status FROM financial_entries WHERE sale_id = ?', [$s1]) === 'pago', 'venda à vista continua entrando como recebida');

$planIn = [['amount' => '50,00', 'due_date' => '2027-01-10'], ['amount' => '30', 'due_date' => '2027-01-20'], ['amount' => '20', 'due_date' => '2027-02-05']];
$sPl = Inventory::createSale(['customer_id' => $cf, 'payment_method' => $crCode, 'installments' => 3, 'installment_days' => 10, 'installment_plan' => $planIn,
    'items' => [['product_id' => $prodF, 'quantity' => 1, 'unit_price' => 100]]], $uid);
$pp = DB::all('SELECT amount, due_date FROM financial_entries WHERE sale_id = ? ORDER BY installment', [$sPl]);
ok(array_map(fn($r) => [(float)$r['amount'], $r['due_date']], $pp) === [[50.0, '2027-01-10'], [30.0, '2027-01-20'], [20.0, '2027-02-05']], 'plano escolhido (valores e vencimentos) vira as contas a receber');
throws(fn() => Inventory::createSale(['customer_id' => $cf, 'payment_method' => $crCode, 'installments' => 2, 'installment_plan' => [['amount' => 10, 'due_date' => '2027-01-01'], ['amount' => 10, 'due_date' => '2027-02-01']],
    'items' => [['product_id' => $prodF, 'quantity' => 1, 'unit_price' => 100]]], $uid), 'somam', 'parcelas precisam somar o total');
$sFx = Inventory::createSale(['customer_id' => $cf, 'payment_method' => 'credito', 'installments' => 2, 'installment_plan' => [['amount' => 60, 'due_date' => '2030-01-01'], ['amount' => 40, 'due_date' => '2030-02-01']],
    'items' => [['product_id' => $prodF, 'quantity' => 1, 'unit_price' => 100]]], $uid);
$fx2 = DB::all('SELECT amount, due_date FROM financial_entries WHERE sale_id = ? ORDER BY installment', [$sFx]);
$sdF = substr(DB::val('SELECT created_at FROM sales WHERE id = ?', [$sFx]), 0, 10);
ok((float)$fx2[0]['amount'] === 60.0 && $fx2[0]['due_date'] === date('Y-m-d', strtotime("$sdF +30 days")), 'intervalo fixo: valores escolhidos, vencimentos de 30 em 30 dias');
$qPl = Quotes::save(['customer_id' => $cf, 'status' => 'rascunho', 'payment_methods' => [$crCode], 'installments' => 2, 'installment_days' => 20,
    'installment_plan' => [['amount' => 70, 'due_date' => '2027-03-01'], ['amount' => 30, 'due_date' => '2027-04-01']], 'items' => [['product_id' => $prodF, 'quantity' => 1, 'unit_price' => 100]]], $uid);
ok(json_decode(Quotes::get($qPl)['installment_plan'], true)[1] == ['due_date' => '2027-04-01', 'amount' => 30], 'orçamento guarda o plano de parcelas');

echo "Condições de pagamento\n";
$tn = array_column(PaymentTerms::rows(), 'name');
ok(in_array('30/60/90 dias', $tn, true) && in_array('À vista', $tn, true) && in_array('28/56/84 dias', $tn, true), 'já vem com as condições que existiam');
$t1 = PaymentTerms::save(['name' => 'Entrada + 2x', 'installments' => 3, 'interval_days' => 15]);
$r1 = DB::one('SELECT * FROM payment_terms WHERE id = ?', [$t1]);
ok((int)$r1['installments'] === 3 && (int)$r1['interval_days'] === 15, 'cadastra condição com parcelamento');
throws(fn() => PaymentTerms::save(['name' => 'entrada + 2X']), 'já está cadastrada', 'não repete (sem diferenciar maiúsculas)');
PaymentTerms::save(['name' => 'Entrada + 2x', 'installments' => 3, 'interval_days' => 15, 'active' => 0], $t1);
ok(!in_array('Entrada + 2x', array_column(PaymentTerms::forForms(), 'name'), true), 'desativada some das sugestões');
ok(PaymentTerms::save(['name' => 'Entrada + 2x']) === $t1, 'cadastrar de novo uma desativada só reativa');
$t2 = PaymentTerms::save(['name' => 'À vista no PIX']);
ok(DB::val('SELECT installments FROM payment_terms WHERE id = ?', [$t2]) === null, 'condição sem parcelamento');
throws(fn() => PaymentTerms::save(['name' => 'X', 'installments' => 99]), 'Parcelas', 'parcelas válidas');
$sT = Inventory::createSale(['customer_id' => $cf, 'payment_method' => 'pix', 'payment_terms' => 'À vista no PIX', 'items' => [['product_id' => $prodF, 'quantity' => 1, 'unit_price' => 10]]], $uid);
ok(DB::val('SELECT payment_terms FROM sales WHERE id = ?', [$sT]) === 'À vista no PIX', 'venda guarda a condição de pagamento');
PaymentTerms::delete($t2);
ok(DB::val('SELECT payment_terms FROM sales WHERE id = ?', [$sT]) === 'À vista no PIX', 'excluir a condição não muda a venda');
$qT = Quotes::save(['customer_id' => $cf, 'status' => 'aprovado', 'payment_methods' => ['pix'], 'payment_terms' => '30 dias', 'items' => [['product_id' => $prodF, 'quantity' => 1, 'unit_price' => 10]]], $uid);
$sQ = Quotes::convert($qT, 'pix', $uid);
ok(DB::val('SELECT payment_terms FROM sales WHERE id = ?', [$sQ]) === '30 dias', 'venda do orçamento herda a condição');

echo "Entrada + parcelas\n";
Inventory::manualMove(['product_id' => $prodF, 'kind' => 'entrada', 'quantity' => 10, 'unit_cost' => 10], $uid);
$te = DB::one("SELECT * FROM payment_terms WHERE name = 'Entrada + 28/56/84 dias'");
ok($te && (int)$te['entry'] === 1 && (int)$te['installments'] === 3 && (int)$te['interval_days'] === 28, 'já vem "Entrada + 28/56/84 dias"');
$pl = PaymentMethods::plan(3, 28, 400, '2026-11-05', null, true, true);
ok(count($pl) === 4 && !empty($pl[0]['entry']) && $pl[0]['due_date'] === '2026-11-05' && $pl[3]['due_date'] === '2027-01-28', 'entrada no dia + 28/56/84 dias');
near($pl[0]['amount'], 100, 'entrada igual às parcelas');
$pl2 = PaymentMethods::plan(3, 30, 1000, '2026-11-05', null, true, true, 40);
near($pl2[0]['amount'], 400, 'entrada de 40%'); near($pl2[1]['amount'], 200, 'resto dividido nas parcelas');
throws(fn() => PaymentMethods::installments('boleto', 12, 30, true), 'entrada + 11x', 'entrada conta como um dos pagamentos');
throws(fn() => PaymentMethods::installments('pix', 1, 30, true), 'não aceita entrada', 'forma à vista não tem entrada');
$sE = Inventory::createSale(['customer_id' => $cf, 'payment_method' => 'boleto', 'installments' => 3, 'installment_days' => 28, 'installment_entry' => 1,
    'items' => [['product_id' => $prodF, 'quantity' => 1, 'unit_price' => 400]]], $uid);
$fe2 = DB::all('SELECT description, amount, due_date FROM financial_entries WHERE sale_id = ? ORDER BY installment', [$sE]);
$sdE = substr(DB::val('SELECT created_at FROM sales WHERE id = ?', [$sE]), 0, 10);
ok(count($fe2) === 4 && str_ends_with($fe2[0]['description'], 'entrada') && $fe2[0]['due_date'] === $sdE, 'venda com entrada: entrada vence no dia');
ok(str_ends_with($fe2[1]['description'], 'parcela 1/3') && $fe2[1]['due_date'] === date('Y-m-d', strtotime("$sdE +28 days")), 'parcelas a cada 28 dias depois da entrada');
$tP = PaymentTerms::save(['name' => 'Entrada de 30% + 2x', 'installments' => 2, 'interval_days' => 30, 'entry' => 1, 'entry_percent' => '30']);
$rP = DB::one('SELECT * FROM payment_terms WHERE id = ?', [$tP]);
ok((int)$rP['entry'] === 1 && abs((float)$rP['entry_percent'] - 30) < 0.01, 'condição com entrada em percentual');
throws(fn() => PaymentTerms::save(['name' => 'X%', 'installments' => 2, 'entry' => 1, 'entry_percent' => '100']), 'Percentual', 'percentual válido');
$qE = Quotes::save(['customer_id' => $cf, 'status' => 'aprovado', 'payment_methods' => ['boleto'], 'installments' => 2, 'installment_entry' => 1, 'installment_days' => 30,
    'items' => [['product_id' => $prodF, 'quantity' => 1, 'unit_price' => 90]]], $uid);
ok((int)Quotes::get($qE)['installment_entry'] === 1 && count(json_decode(Quotes::get($qE)['installment_plan'], true)) === 3, 'orçamento com entrada');
$sQE = Quotes::convert($qE, 'boleto', $uid);
ok((int)DB::val('SELECT installment_entry FROM sales WHERE id = ?', [$sQE]) === 1 && (int)DB::val('SELECT COUNT(*) FROM financial_entries WHERE sale_id = ?', [$sQE]) === 3, 'venda do orçamento herda a entrada');

echo "Vencimentos acompanham a data da venda\n";
$d0 = date('Y-m-d', strtotime('-11 days'));
$qS = Quotes::save(['customer_id' => $cf, 'status' => 'aprovado', 'request_date' => $d0, 'payment_methods' => ['boleto'], 'installments' => 3, 'installment_days' => 30,
    'installment_plan' => [['amount' => 30, 'due_date' => date('Y-m-d', strtotime("$d0 +28 days"))], ['amount' => 30, 'due_date' => date('Y-m-d', strtotime("$d0 +58 days"))], ['amount' => 30, 'due_date' => date('Y-m-d', strtotime("$d0 +88 days"))]],
    'items' => [['product_id' => $prodF, 'quantity' => 1, 'unit_price' => 90]]], $uid);
ok(json_decode(Quotes::get($qS)['installment_plan'], true)[0]['due_date'] === date('Y-m-d', strtotime("$d0 +28 days")), 'orçamento guarda o vencimento escolhido');
$sS = Quotes::convert($qS, 'boleto', $uid);
$feS = DB::all('SELECT due_date FROM financial_entries WHERE sale_id = ? ORDER BY installment', [$sS]);
$hoje = date('Y-m-d');
ok(array_column($feS, 'due_date') === [date('Y-m-d', strtotime("$hoje +28 days")), date('Y-m-d', strtotime("$hoje +58 days")), date('Y-m-d', strtotime("$hoje +88 days"))], 'vencimentos andam os dias entre o orçamento e a venda');
ok(Quotes::shiftPlan('[{"amount":10,"due_date":"2026-12-03"}]', '2026-11-05', '2026-11-16') === '[{"amount":10,"due_date":"2026-12-14"}]', 'shiftPlan: 05/11 → 16/11 leva 03/12 para 14/12');
ok(Quotes::shiftPlan('[{"amount":10,"due_date":"2026-12-03"}]', '2026-11-16', '2026-11-16') === '[{"amount":10,"due_date":"2026-12-03"}]', 'shiftPlan: venda no mesmo dia não muda nada');

echo "Venda direta com serviço e custos adicionais\n";
$servV = Inventory::saveProduct(['name' => 'Visita técnica', 'type' => 'servico', 'sale_price' => 120, 'avg_cost' => 20]);
$estV = (float)Inventory::product($prodF)['stock'];
$sV = Inventory::createSale(['customer_id' => $cf, 'payment_method' => 'pix', 'discount' => 10,
    'items' => [['product_id' => $prodF, 'quantity' => 1, 'unit_price' => 50], ['product_id' => $servV, 'quantity' => 2, 'unit_price' => 120]],
    'extras' => [['description' => 'Frete', 'amount' => '35,50'], ['description' => '', 'amount' => '']]], $uid);
$rowV = DB::one('SELECT * FROM sales WHERE id = ?', [$sV]);
ok(abs((float)$rowV['surcharge'] - 35.5) < 0.001 && abs((float)$rowV['total'] - (50 + 240 + 35.5 - 10)) < 0.001, 'custos adicionais entram no acréscimo e no total');
ok(json_decode($rowV['extras'], true) === [['description' => 'Frete', 'amount' => 35.5]], 'custos adicionais guardados com a descrição (linha vazia ignorada)');
ok(abs((float)Inventory::product($prodF)['stock'] - ($estV - 1)) < 0.001 && !DB::val('SELECT COUNT(*) FROM movements WHERE sale_id = ? AND product_id = ?', [$sV, $servV]), 'serviço na venda não mexe no estoque');
ok(abs((float)DB::val('SELECT cost_total FROM sales WHERE id = ?', [$sV]) - ((float)DB::val('SELECT unit_cost FROM sale_items WHERE sale_id = ? AND product_id = ?', [$sV, $prodF]) + 40)) < 0.001, 'custo do serviço entra no custo da venda');
throws(fn() => Inventory::createSale(['customer_id' => $cf, 'payment_method' => 'pix', 'items' => [['product_id' => $servV, 'quantity' => 1]], 'extras' => [['description' => '', 'amount' => 10]]], $uid), 'Descreva', 'custo adicional precisa de descrição');
$qX = Quotes::save(['customer_id' => $cf, 'status' => 'aprovado', 'items' => [['product_id' => $servV, 'quantity' => 1, 'unit_price' => 100]], 'extras' => [['description' => 'Deslocamento', 'amount' => 25]]], $uid);
$sX = Quotes::convert($qX, 'pix', $uid);
ok(json_decode(DB::val('SELECT extras FROM sales WHERE id = ?', [$sX]), true)[0]['description'] === 'Deslocamento' && abs((float)DB::val('SELECT total FROM sales WHERE id = ?', [$sX]) - 125) < 0.001, 'venda do orçamento leva os custos adicionais descritos');

$sN = Inventory::createSale(['customer_id' => $cf, 'payment_method' => 'pix', 'note' => str_repeat('a', 300) . "\nlinha 2", 'items' => [['product_id' => $servV, 'quantity' => 1]]], $uid);
ok(mb_strlen(DB::val('SELECT note FROM sales WHERE id = ?', [$sN])) === 308, 'observações da venda passam de 255 caracteres e mantêm a quebra de linha');

echo "Comportamento: vendas, notas e ordens de compra\n";
// Configurações validadas
$sv = Settings::save(['invoice_cnpj_mode' => 'xyz', 'sale_max_discount' => '12,5', 'sale_cancel_days' => '-3', 'sale_negative_stock' => 'on', 'po_statuses' => '{"enviada":{"label":"Pedido enviado","color":"#123456"},"xx":{"label":"y"},"recebida":{"color":"azul"}}']);
ok($sv['invoice_cnpj_mode'] === 'cadastrados' && $sv['sale_max_discount'] === '12.5' && $sv['sale_cancel_days'] === '0' && $sv['sale_negative_stock'] === '1', 'comportamento: valores inválidos voltam ao padrão');
ok(json_decode($sv['po_statuses'], true) === ['enviada' => ['label' => 'Pedido enviado', 'color' => '#123456']], 'status da ordem: guarda só nome/cor válidos dos 5 status');
ok(PurchaseOrders::label('enviada') === 'Pedido enviado' && PurchaseOrders::label('rascunho') === 'Rascunho', 'nome do status da ordem trocado');
throws(fn() => Settings::save(['sale_default_method' => 'naoexiste']), 'Forma de pagamento', 'forma de pagamento padrão precisa existir');
// Estoque negativo
$pN = Inventory::saveProduct(['name' => 'Peça sem estoque', 'type' => 'produto', 'sale_price' => 10]);
Settings::save(['sale_negative_stock' => '0']);
throws(fn() => Inventory::createSale(['customer_id' => $cf, 'payment_method' => 'pix', 'items' => [['product_id' => $pN, 'quantity' => 2]]], $uid), 'Estoque insuficiente', 'sem estoque: bloqueia (padrão)');
Settings::save(['sale_negative_stock' => '1']);
Inventory::createSale(['customer_id' => $cf, 'payment_method' => 'pix', 'items' => [['product_id' => $pN, 'quantity' => 2]]], $uid);
ok(abs((float)Inventory::product($pN)['stock'] + 2) < 0.001, 'com "deixar vender sem estoque": o estoque fica negativo');
Settings::save(['sale_negative_stock' => '0']);
// À vista em aberto
Settings::save(['sale_cash_open' => '1']);
$sA = Inventory::createSale(['customer_id' => $cf, 'payment_method' => 'pix', 'items' => [['product_id' => $servV, 'quantity' => 1]]], $uid);
$fA = DB::one('SELECT status, paid_at FROM financial_entries WHERE sale_id = ?', [$sA]);
ok($fA['status'] === 'aberto' && $fA['paid_at'] === null, 'venda à vista entra em aberto quando configurado');
Settings::save(['sale_cash_open' => '0']);
// Desconto máximo e cancelamento para não administradores
$mem = Auth::createUser(['name' => 'Vendedor', 'email' => 'vend@example.com', 'password' => 'senha12345', 'permissions' => ['vendas']]);
Settings::save(['sale_max_discount' => '10']);
throws(fn() => Inventory::createSale(['customer_id' => $cf, 'payment_method' => 'pix', 'discount' => 20, 'items' => [['product_id' => $servV, 'quantity' => 1, 'unit_price' => 100]]], $mem), 'desconto máximo', 'vendedor não passa do desconto máximo');
$sM = Inventory::createSale(['customer_id' => $cf, 'payment_method' => 'pix', 'discount' => 10, 'items' => [['product_id' => $servV, 'quantity' => 1, 'unit_price' => 100]]], $mem);
ok($sM > 0, 'desconto dentro do limite: passa');
ok(Inventory::createSale(['customer_id' => $cf, 'payment_method' => 'pix', 'discount' => 50, 'items' => [['product_id' => $servV, 'quantity' => 1, 'unit_price' => 100]]], $uid) > 0, 'administrador não tem limite de desconto');
Settings::save(['sale_max_discount' => '0', 'sale_cancel_admin' => '1']);
throws(fn() => Inventory::cancelSale($sM, $mem), 'administradores', 'só administrador cancela quando configurado');
Settings::save(['sale_cancel_admin' => '0', 'sale_cancel_days' => '2']);
DB::run('UPDATE sales SET created_at = ? WHERE id = ?', [date('Y-m-d H:i:s', strtotime('-5 days')), $sM]);
throws(fn() => Inventory::cancelSale($sM, $mem), 'até 2 dias', 'prazo para cancelar');
Inventory::cancelSale($sM, $uid);
ok(DB::val('SELECT status FROM sales WHERE id = ?', [$sM]) === 'cancelada', 'administrador cancela fora do prazo');
Settings::save(['sale_cancel_days' => '0']);
// Notas: qualquer CNPJ, tributação e preço de venda
Settings::save(['invoice_cnpj_mode' => 'qualquer']);
ok(Invoices::recipientError(['kind' => 'nfe', 'recipient_doc' => '11222333000181']) === null && Invoices::recipientError(['kind' => 'nfce']) === null, 'aceitar qualquer CNPJ: não confere o destinatário');
Settings::save(['invoice_cnpj_mode' => 'cadastrados']);
ok(Invoices::recipientError(['kind' => 'nfe', 'recipient_doc' => '11222333000181']) !== null, 'só os cadastrados: volta a conferir');
$pT = Inventory::saveProduct(['name' => 'Item tributado', 'type' => 'produto', 'fiscal' => ['ncm' => '84145990']]);
ok(!Fiscal::fillFromInvoice($pT, '84149039', null, null, 'nunca') && Fiscal::fillFromInvoice($pT, '84149039', null, null, 'vazios') === false, 'tributação: "nunca" e "só vazios" não trocam o NCM');
ok(Fiscal::fillFromInvoice($pT, '84149039', null, null, 'sempre') && Fiscal::decode(DB::val('SELECT fiscal FROM products WHERE id = ?', [$pT]))['ncm'] === '84149039', 'tributação: "sempre" atualiza o NCM');
$invP = Invoices::confirm(['kind' => 'manual', 'supplier_name' => 'Fornecedor X', 'number' => '901',
    'items' => [['import' => 1, 'product_id' => $pT, 'qty' => 2, 'cost_total' => 100, 'sale_price' => '80,00']]], $uid);
ok((float)Inventory::product($pT)['sale_price'] === 80.0, 'nota: atualiza o preço de venda confirmado na conferência');

echo "Ordens de compra: ações em massa\n";
$poA = PurchaseOrders::save(['supplier_id' => $f1, 'items' => [['product_id' => $prodF, 'quantity' => 1, 'unit_cost' => 5]]], $uid);
$poB = PurchaseOrders::save(['supplier_id' => $f1, 'items' => [['product_id' => $prodF, 'quantity' => 2, 'unit_cost' => 5]]], $uid);
PurchaseOrders::setStatus($poB, 'recebida');
$rb = PurchaseOrders::bulk([$poA, $poB], 'status', 'enviada');
ok($rb['ok'] === 1 && count($rb['errors']) === 1 && $rb['errors'][0]['id'] === $poB && PurchaseOrders::get($poA)['status'] === 'enviada', 'em massa: muda o status só das que podem (recebida não volta a enviada)');
$rb = PurchaseOrders::bulk([$poA, $poB], 'status', 'recebida', '2026-11-10');
ok($rb['ok'] === 1 && PurchaseOrders::get($poA)['received_at'] === '2026-11-10', 'em massa: mercadoria recebida com a data');
throws(fn() => PurchaseOrders::bulk([$poA], 'status', 'xyz'), 'Status inválido', 'em massa: status inválido');
$rb = PurchaseOrders::bulk([$poA], 'status', 'cancelada');
ok($rb['ok'] === 0 && str_contains($rb['errors'][0]['error'], 'não pode ir'), 'em massa: recebida não pode ser cancelada');
$poC = PurchaseOrders::save(['supplier_id' => $f1, 'items' => [['product_id' => $prodF, 'quantity' => 1, 'unit_cost' => 5]]], $uid);
$rb = PurchaseOrders::bulk([$poC, $poB, 999999], 'delete');
ok($rb['ok'] === 1 && count($rb['errors']) === 2 && !DB::val('SELECT id FROM purchase_orders WHERE id = ?', [$poC]), 'em massa: exclui só as em rascunho/canceladas');

echo "Foto de perfil\n";
$png = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
ok(Auth::setAvatar($uid, $png) === $png && Auth::withAvatar(['id' => $uid])['avatar'] === $png, 'grava a foto de perfil');
throws(fn() => Auth::setAvatar($uid, 'data:image/gif;base64,R0lGODlhAQABAAAAACw='), 'PNG, JPG ou WebP', 'recusa formato não aceito');
throws(fn() => Auth::setAvatar($uid, 'data:image/png;base64,' . base64_encode('não é imagem')), 'inválido', 'recusa arquivo que não é imagem');
throws(fn() => Auth::setAvatar($uid, 'data:image/png;base64,' . base64_encode(str_repeat('a', 210 * 1024))), 'grande demais', 'recusa foto grande');
ok(Auth::setAvatar($uid, '') === null && DB::val('SELECT avatar FROM users WHERE id = ?', [$uid]) === null, 'remove a foto');

echo "Ações do orçamento\n";
$qa = Quotes::save(['customer_id' => $cf, 'status' => 'rascunho', 'items' => [['product_id' => $prodF, 'quantity' => 1, 'unit_price' => 10]]], $uid);
ok(Quotes::mark($qa, 'enviado') === 'enviado' && Quotes::get($qa)['status'] === 'enviado', 'marcar como enviado (padrão: Aguardando aprovação/enviado)');
Settings::save(['quote_on_sent' => 'aguardando_envio', 'quote_on_approved' => 'aprovado']);
Quotes::setStatus($qa, 'rascunho');
ok(Quotes::mark($qa, 'enviado') === 'aguardando_envio', 'comportamento configurável: "enviado" vai para o status escolhido');
ok(Quotes::mark($qa, 'recusado') === 'recusado', 'marcar como recusado');
ok(Quotes::mark($qa, 'aprovado') === 'aprovado', 'marcar como aprovado');
Settings::save(['quote_on_sent' => 'enviado']);
$qd2 = Quotes::duplicate($qa, $uid);
$gd = Quotes::get($qd2);
ok($gd['status'] === 'rascunho' && (int)$gd['customer_id'] === $cf && count($gd['items']) === 1, 'duplicar (API): rascunho com o mesmo cliente e os mesmos itens');
throws(fn() => Quotes::save(['status' => 'rascunho', 'items' => [['product_id' => $prodF, 'quantity' => 1]]], $uid), 'Selecione o cliente', 'não salva orçamento sem cliente (nem rascunho)');
throws(fn() => Quotes::save(['target' => 'lead', 'lead_name' => '', 'status' => 'rascunho', 'items' => [['product_id' => $prodF, 'quantity' => 1]]], $uid), 'lead', 'lead sem nome não salva');
$ql = Quotes::save(['target' => 'lead', 'lead_name' => 'Fulano Lead', 'status' => 'rascunho', 'items' => [['product_id' => $prodF, 'quantity' => 1, 'unit_price' => 10]]], $uid);
ok(Quotes::mark($ql, 'enviado') === 'enviado', 'lead pode ser marcado como enviado');
throws(fn() => Quotes::mark($ql, 'aprovado'), 'cliente cadastrado', 'lead não é marcado como aprovado');
throws(fn() => Quotes::setStatus($ql, 'aprovado'), 'cliente cadastrado', 'lead não muda para aprovado');
throws(fn() => Quotes::save(['target' => 'lead', 'lead_name' => 'Fulano Lead', 'status' => 'aprovado', 'items' => [['product_id' => $prodF, 'quantity' => 1]]], $uid, $ql), 'cliente cadastrado', 'lead não é salvo como aprovado');
throws(fn() => Quotes::clientRespond(Quotes::get($ql)['public_token'], 'aprovar', null), 'entre em contato', 'lead não aprova pelo link');
DB::update('quotes', $ql, ['status' => 'aprovado']);
throws(fn() => Quotes::convert($ql, 'pix', $uid), 'cliente', 'lead não gera venda');
Quotes::delete($ql);
Quotes::delete($qd2);
ok(!DB::val('SELECT id FROM quotes WHERE id = ?', [$qd2]) && !DB::val('SELECT COUNT(*) FROM quote_items WHERE quote_id = ?', [$qd2]), 'excluir orçamento');
$sv = Quotes::convert($qa, 'pix', $uid);
throws(fn() => Quotes::delete($qa), 'não pode ser excluído', 'orçamento que virou venda não é excluído');

echo "Indicadores do mês\n";
$sm0 = Quotes::summary();
$qm = Quotes::save(['customer_id' => $cf, 'status' => 'enviado', 'expires_at' => date('Y-m-t'), 'items' => [['product_id' => $prodF, 'quantity' => 1, 'unit_price' => 10]]], $uid);
ok(Quotes::summary()['expiram_mes'] === $sm0['expiram_mes'] + 1, 'expira no fim do mês conta em "Expiram este mês"');
DB::update('quotes', $qm, ['expires_at' => date('Y-m-d', strtotime(date('Y-m-t') . ' +1 day'))]);
ok(Quotes::summary()['expiram_mes'] === $sm0['expiram_mes'], 'expira no mês que vem não conta');
Quotes::setStatus($qm, 'recusado');
ok(Quotes::summary()['recusados_mes'] === $sm0['recusados_mes'] + 1, 'recusado no mês');
DB::update('quotes', $qm, ['refused_at' => date('Y-m-d H:i:s', strtotime(date('Y-m-01') . ' -1 day'))]);
ok(Quotes::summary()['recusados_mes'] === $sm0['recusados_mes'], 'recusado no mês passado não conta');
Quotes::delete($qm);

echo "Ações em massa e PDF\n";
$b1 = Quotes::save(['customer_id' => $cf, 'status' => 'rascunho', 'items' => [['product_id' => $prodF, 'quantity' => 2, 'unit_price' => 15]]], $uid);
$b2 = Quotes::save(['target' => 'lead', 'lead_name' => 'Lead Massa', 'status' => 'rascunho', 'items' => [['product_id' => $prodF, 'quantity' => 1, 'unit_price' => 10]]], $uid);
$r = Quotes::bulk([$b1, $b2], 'status', 'em_elaboracao');
ok($r['ok'] === 2 && !$r['errors'] && Quotes::get($b2)['status'] === 'em_elaboracao', 'alterar status em massa');
$r = Quotes::bulk([$b1, $b2], 'aprovado');
ok($r['ok'] === 1 && count($r['errors']) === 1 && $r['errors'][0]['id'] === $b2, 'aprovar em massa: lead fica de fora com o motivo, o resto é aprovado');
ok(Quotes::bulk([$b1, $b2], 'recusado')['ok'] === 2, 'recusar em massa');
throws(fn() => Quotes::bulk([], 'delete'), 'Selecione', 'sem seleção');
throws(fn() => Quotes::bulk([$b1], 'xpto'), 'Ação inválida', 'ação inválida');
throws(fn() => Quotes::bulk([$b1], 'status', 'vendido'), 'Status inválido', 'não muda para vendido em massa');
$pdf = QuotePdf::render(Quotes::get($b1), Settings::all());
ok(str_starts_with($pdf, '%PDF-1.4') && str_contains($pdf, '%%EOF') && str_contains($pdf, '/Count 1'), 'PDF do orçamento gerado');
$zw = new ZipWriter(); $zw->add('a.pdf', $pdf); $zw->add('b.pdf', 'texto'); $zf = sys_get_temp_dir() . '/t' . uniqid() . '.zip'; file_put_contents($zf, $zw->output());
if (class_exists('ZipArchive')) { $za = new ZipArchive(); $za->open($zf); ok($za->numFiles === 2 && $za->getFromName('a.pdf') === $pdf && $za->getFromName('b.pdf') === 'texto', 'ZIP válido (lido pelo ZipArchive)'); $za->close(); }
@unlink($zf);
ok(QuotePdf::fileName(['id' => 7, 'customer_id' => 1, 'customer_trade_name' => 'Ação & Cia', 'customer_name' => '']) === 'orcamento-7-acao-cia.pdf', 'nome do arquivo do PDF');
$r = Quotes::bulk([$b1, $b2], 'delete');
ok($r['ok'] === 2 && !DB::val('SELECT COUNT(*) FROM quotes WHERE id IN (?, ?)', [$b1, $b2]), 'excluir em massa');

echo "Filtros das listas\n";
$qf = fn(array $in) => (function () use ($in) { $w = ['1=1']; $p = [];
    Reports::listFilters($in, $w, $p, ['created' => 'q.created_at', 'total' => 'q.total', 'user' => 'q.user_id', 'names' => ['c.name', 'c.trade_name', 'q.lead_name'], 'doc' => 'c.document']);
    return array_map('intval', array_column(DB::all('SELECT q.id FROM quotes q LEFT JOIN customers c ON c.id = q.customer_id WHERE ' . implode(' AND ', $w) . ' ORDER BY q.id', $p), 'id')); })();
$fa = Quotes::save(['customer_id' => $cf, 'status' => 'rascunho', 'items' => [['product_id' => $prodF, 'quantity' => 1, 'unit_price' => 100]]], $uid);
$fb = Quotes::save(['target' => 'lead', 'lead_name' => 'Zuleica Filtro', 'status' => 'rascunho', 'items' => [['product_id' => $prodF, 'quantity' => 1, 'unit_price' => 2500]]], $uid);
DB::update('quotes', $fa, ['created_at' => '2026-01-15 10:00:00']);
ok($qf(['from' => '2026-01-01', 'to' => '2026-01-31']) === [$fa], 'filtro por período de criação');
ok(in_array($fb, $qf(['customer' => 'zuleica'])) && !in_array($fa, $qf(['customer' => 'zuleica'])), 'filtro por cliente/lead (sem maiúsculas)');
ok(in_array($fb, $qf(['min' => '2.000,00'])) && !in_array($fa, $qf(['min' => '2.000,00'])), 'valor mínimo (aceita 2.000,00)');
ok(in_array($fa, $qf(['max' => '150'])) && !in_array($fb, $qf(['max' => '150'])), 'valor máximo');
ok($qf(['from' => '2026-01-31', 'to' => '2026-01-01']) === [$fa], 'período invertido é corrigido');
ok(count($qf(['user_id' => 999999])) === 0, 'filtro por responsável');
Quotes::delete($fa); Quotes::delete($fb);

echo "Status do orçamento\n";
ok(count(QuoteStatuses::rows()) === 7 && QuoteStatuses::label('enviado') === 'Aguardando aprovação', 'status do sistema já vêm cadastrados');
$sid = QuoteStatuses::save(['name' => 'Em negociação', 'color' => '#DB2777']);
$code = DB::val('SELECT code FROM quote_statuses WHERE id = ?', [$sid]);
ok($code === 'st_' . $sid && in_array($code, QuoteStatuses::selectable(), true), 'cria status novo (código próprio, já selecionável)');
ok(DB::val("SELECT code FROM quote_statuses ORDER BY sort DESC, id DESC LIMIT 1") === 'vendido', '"Vendido" continua por último');
throws(fn() => QuoteStatuses::save(['name' => 'em negociação', 'color' => '#000000']), 'Já existe', 'não repete nome');
throws(fn() => QuoteStatuses::save(['name' => 'X', 'color' => 'azul']), 'cor', 'cor inválida');
$qs = Quotes::save(['customer_id' => $cf, 'status' => $code, 'items' => [['product_id' => $prodF, 'quantity' => 1, 'unit_price' => 10]]], $uid);
ok(Quotes::get($qs)['status_label'] === 'Em negociação', 'orçamento com status personalizado');
$sysId = (int)DB::val("SELECT id FROM quote_statuses WHERE code = 'enviado'");
QuoteStatuses::save(['name' => 'Enviado ao cliente', 'color' => '#2563eb'], $sysId);
ok(QuoteStatuses::label('enviado') === 'Enviado ao cliente', 'renomeia status do sistema');
throws(fn() => QuoteStatuses::delete($sysId), 'sistema', 'status do sistema não é excluído');
throws(fn() => QuoteStatuses::save(['name' => 'Enviado ao cliente', 'color' => '#2563eb', 'active' => 0], $sysId), 'sistema', 'nem desativado');
throws(fn() => QuoteStatuses::delete($sid), 'Há orçamentos', 'status em uso não é excluído');
QuoteStatuses::save(['name' => 'Em negociação', 'color' => '#db2777', 'active' => 0], $sid);
ok(!in_array($code, QuoteStatuses::selectable(), true), 'desativado sai da lista de escolha');
Quotes::save(['customer_id' => $cf, 'status' => $code, 'items' => [['product_id' => $prodF, 'quantity' => 1, 'unit_price' => 10]]], $uid, $qs);
ok(true, 'orçamento que já estava no status desativado continua salvando');
throws(fn() => Quotes::save(['customer_id' => $cf, 'status' => $code, 'items' => [['product_id' => $prodF, 'quantity' => 1]]], $uid), 'Status inválido', 'orçamento novo não usa status desativado');
Quotes::setStatus($qs, 'rascunho'); QuoteStatuses::delete($sid);
ok(!DB::val('SELECT id FROM quote_statuses WHERE id = ?', [$sid]), 'exclui status sem uso');
QuoteStatuses::save(['name' => 'Aguardando aprovação', 'color' => '#d97706'], $sysId);

echo "\nTributação do produto: ";
$fp = Inventory::saveProduct(['name' => 'Rolamento 6205', 'type' => 'produto', 'fiscal' => [
    'origem' => '0', 'ncm' => '8482.10.10', 'cest' => '01.999.00', 'cfop' => '5102', 'unidade' => 'pc', 'cbenef' => 'pr-800000',
    'icms' => ['csosn' => '102', 'p_icms_st' => '18'],
    'pis' => ['cst' => '49', 'calc' => 'percentual', 'p' => '0,65', 'v_unit' => '9'], 'cofins' => ['cst' => '99', 'calc' => ''],
    'ipi' => ['cst' => '99', 'enq' => '999', 'calc' => '', 'devolvido' => false], 'ibs_cbs' => ['cst' => '000', 'cclass' => '000001'],
]]);
$ff = Fiscal::decode(Inventory::product($fp)['fiscal']);
ok($ff['ncm'] === '84821010' && $ff['cest'] === '0199900' && $ff['cfop'] === '5102' && $ff['unidade'] === 'PC' && $ff['cbenef'] === 'PR800000', 'grava NCM, CEST, CFOP, unidade e cBenef limpos');
ok($ff['icms'] === ['csosn' => '102'], 'CSOSN 102 descarta os campos de ICMS ST');
ok($ff['pis'] === ['cst' => '49', 'calc' => 'percentual', 'p' => 0.65], 'PIS percentual guarda a alíquota e descarta o valor unitário');
ok($ff['cofins'] === ['cst' => '99'] && $ff['ipi'] === ['cst' => '99', 'enq' => '999'] && $ff['ibs_cbs']['cclass'] === '000001', 'COFINS, IPI e IBS/CBS');
$f9 = Fiscal::normalize(['icms' => ['csosn' => '900', 'mod_bc' => '3', 'p_icms' => '18', 'mod_bc_st' => '4', 'p_mva_st' => '120,5', 'p_cred_sn' => '1,25'], 'ipi' => ['calc' => 'percentual', 'p' => '7,8', 'devolvido' => true, 'p_devol' => '100']]);
ok($f9['icms']['p_mva_st'] == 120.5 && $f9['icms']['p_cred_sn'] == 1.25 && $f9['icms']['mod_bc'] === '3' && $f9['ipi']['p'] == 7.8 && $f9['ipi']['devolvido'] === true, 'CSOSN 900 aceita ICMS próprio, ST e crédito; IPI percentual e devolvido');
ok(Fiscal::normalize(['icms' => ['csosn' => '500', 'v_icms_st_ret' => '12,30']])['icms']['v_icms_st_ret'] == 12.3, 'CSOSN 500 guarda o ST retido');
throws(fn() => Fiscal::normalize(['ncm' => '1234']), 'NCM', 'NCM com tamanho errado');
throws(fn() => Fiscal::normalize(['cfop' => '4102']), 'CFOP', 'CFOP inválido');
throws(fn() => Fiscal::normalize(['icms' => ['csosn' => '999']]), 'CSOSN', 'CSOSN inexistente');
throws(fn() => Fiscal::normalize(['pis' => ['cst' => '49', 'calc' => 'percentual', 'p' => '150']]), 'Alíquota do PIS', 'alíquota acima de 100%');
ok(Fiscal::normalize([]) === [] && Inventory::product(Inventory::saveProduct(['name' => 'Sem fiscal', 'type' => 'produto', 'fiscal' => []]))['fiscal'] === null, 'sem dados fiscais grava vazio');
Inventory::saveProduct(['name' => 'Rolamento 6205', 'type' => 'produto', 'active' => 1], $fp);
ok(Fiscal::decode(Inventory::product($fp)['fiscal'])['ncm'] === '84821010', 'salvar sem a aba mantém a tributação');
ok(Inventory::product(Inventory::saveProduct(['name' => 'Serviço X', 'type' => 'servico', 'fiscal' => ['ncm' => '84821010']]))['fiscal'] === null, 'serviço não guarda tributação');
// Importação de nota completa NCM, CEST e Origem em branco
$px = Invoices::parseXml(file_get_contents($fx . '/nfe-duplicatas.xml'));
ok($px['items'][3]['ncm'] === '84823000' && $px['items'][3]['orig'] === '1', 'XML traz NCM e origem de cada item');
$it = $px['items'][3];
$invT = Invoices::confirm(['kind' => 'manual', 'supplier_name' => 'Rolimac', 'number' => '1', 'items' => [
    array_merge($it, ['import' => 1, 'factor' => 1, 'new_product' => ['name' => 'Rolamento 22209', 'type' => 'produto', 'unit' => 'PC']]),
    ['import' => 1, 'product_id' => $fp, 'qty' => 1, 'cost_total' => 10, 'ncm' => '99999999', 'cest' => '1234567', 'orig' => '2'],
]], $uid);
$newPid = (int)DB::val('SELECT product_id FROM invoice_items WHERE invoice_id = ? ORDER BY id LIMIT 1', [$invT]);
$nf = Fiscal::decode(Inventory::product($newPid)['fiscal']);
ok($nf['ncm'] === '84823000' && $nf['origem'] === '2' && !isset($nf['cest']), 'produto novo recebe NCM e origem da nota (1 vira 2)');
$ff = Fiscal::decode(Inventory::product($fp)['fiscal']);
ok($ff['ncm'] === '84821010' && $ff['cest'] === '0199900' && $ff['origem'] === '0', 'não sobrescreve o que já estava preenchido');

echo "\nCor de destaque do usuário: ";
ok(Auth::setAccent($uid, '#1D4ED8') === '#1d4ed8' && DB::val('SELECT accent_color FROM users WHERE id = ?', [$uid]) === '#1d4ed8', 'grava a cor do usuário em minúsculas');
throws(fn() => Auth::setAccent($uid, 'azul'), 'Cor inválida', 'recusa cor fora do formato #RRGGBB');
ok(Auth::setAccent($uid, '') === null && Auth::setAccent($uid, '#B02A37') === null, 'em branco ou a cor padrão voltam ao padrão (null)');

echo "\nRazão social: ";
Settings::save(['company_name' => 'SS Compressores', 'company_legal_name' => 'SS Compressores e Perfuratrizes Ltda']);
$pdfL = QuotePdf::render(Quotes::get((int)DB::val('SELECT MAX(id) FROM quotes')), Settings::all());
preg_match_all('/stream\n(.*?)\nendstream/s', $pdfL, $pm);
ok(Settings::get('company_legal_name') === 'SS Compressores e Perfuratrizes Ltda' && (bool)array_filter($pm[1], fn($z) => str_contains((string)@gzuncompress($z), 'Perfuratrizes Ltda')), 'grava a razão social e mostra no PDF');

echo "\nMarca e referências cruzadas: ";
$rp = Inventory::saveProduct(['name' => 'Filtro de óleo', 'type' => 'produto', 'sku' => 'FO-100', 'brand' => ' Mann ', 'mfr_code' => 'W 712/75',
    'refs' => [['manufacturer' => 'Tecfil', 'code' => 'PSL-55'], ['manufacturer' => 'Fram', 'code' => 'ph 5949'], ['manufacturer' => 'Tecfil', 'code' => 'PSL55'], ['manufacturer' => '', 'code' => '  ']]]);
$rpp = Inventory::product($rp);
ok($rpp['brand'] === 'Mann' && $rpp['mfr_code'] === 'W 712/75', 'grava marca e código do fabricante');
ok(count(Inventory::refs($rp)) === 2, 'referências: ignora linhas vazias e códigos repetidos do mesmo fabricante');
$find = function (string $q) { [$sq, $sp] = Inventory::searchSql($q); return array_column(DB::all("SELECT p.id FROM products p WHERE $sq", $sp), 'id'); };
ok(in_array($rp, $find('w712/75')) && in_array($rp, $find('W-712-75')), 'busca pelo código do fabricante ignorando traços e espaços');
ok(in_array($rp, $find('psl 55')) && in_array($rp, $find('PH5949')), 'busca pelas referências cruzadas');
ok(in_array($rp, $find('fo100')) && in_array($rp, $find('mann')), 'busca pelo SKU sem traço e pela marca');
ok(!in_array($rp, $find('XYZ999')), 'código que não existe não acha o produto');
Inventory::saveProduct(['name' => 'Filtro de óleo', 'type' => 'produto', 'active' => 1], $rp);
ok(count(Inventory::refs($rp)) === 2 && Inventory::product($rp)['brand'] === 'Mann', 'salvar sem os campos mantém marca e referências');
Inventory::saveProduct(['name' => 'Filtro de óleo', 'type' => 'produto', 'refs' => []], $rp);
ok(Inventory::refs($rp) === [], 'lista vazia apaga as referências');
throws(fn() => Inventory::saveProduct(['name' => 'X', 'refs' => [['manufacturer' => 'SKF', 'code' => '']]]), 'Informe o código', 'referência com fabricante e sem código');
ok(in_array('product_refs', Backup::TABLES, true), 'referências entram no backup');

echo "\nDescrição e valor de compra: ";
$dp = Inventory::saveProduct(['name' => 'Correia A-42', 'type' => 'produto', 'description' => "Correia em V\r\nPerfil A, 42 pol."]);
ok(Inventory::product($dp)['description'] === "Correia em V\nPerfil A, 42 pol.", 'grava a descrição');
Inventory::move($dp, 'entrada', 10, 20.0, ['user_id' => $uid]);
Inventory::saveProduct(['name' => 'Correia A-42', 'type' => 'produto', 'avg_cost' => '25,50'], $dp, $uid);
$dpp = Inventory::product($dp);
$adj = DB::one("SELECT * FROM movements WHERE product_id = ? AND type = 'ajuste_custo'", [$dp]);
ok(abs($dpp['avg_cost'] - 25.5) < 1e-6 && abs($dpp['stock'] - 10) < 1e-6, 'edição troca o valor de compra sem mexer no estoque');
ok($adj && (float)$adj['quantity'] == 0 && str_contains($adj['note'], 'R$ 20,00 para R$ 25,50') && (int)$adj['user_id'] === $uid, 'ajuste de custo fica no histórico com o usuário');
ok(Inventory::setCost($dp, 25.5) === false, 'mesmo valor não gera ajuste');
Inventory::move($dp, 'entrada', 10, 30.0);
ok(abs(Inventory::product($dp)['avg_cost'] - 27.75) < 1e-6, 'próxima compra faz a média a partir do valor ajustado');
Inventory::saveProduct(['name' => 'Correia A-42', 'type' => 'produto', 'active' => 1], $dp);
ok(Inventory::product($dp)['description'] !== null && abs(Inventory::product($dp)['avg_cost'] - 27.75) < 1e-6, 'salvar sem os campos mantém descrição e custo');
throws(fn() => Inventory::setCost($dp, -1), 'negativo', 'valor de compra negativo');

echo "\nEstoque inicial: ";
$ip = Inventory::saveProduct(['name' => 'Válvula de alívio', 'type' => 'produto', 'avg_cost' => '80', 'initial_stock' => '5'], null, $uid);
$ipp = Inventory::product($ip);
$im = DB::one('SELECT * FROM movements WHERE product_id = ?', [$ip]);
ok(abs($ipp['stock'] - 5) < 1e-6 && abs($ipp['avg_cost'] - 80) < 1e-6, 'produto novo com estoque inicial e custo');
ok($im && $im['type'] === 'entrada' && $im['note'] === 'Estoque inicial' && (int)$im['user_id'] === $uid && abs($im['unit_cost'] - 80) < 1e-6, 'estoque inicial vira entrada no histórico');
ok(abs(Inventory::product(Inventory::saveProduct(['name' => 'Sem custo', 'type' => 'produto', 'initial_stock' => '2,5']))['stock'] - 2.5) < 1e-6, 'estoque inicial sem custo');
ok((float)Inventory::product(Inventory::saveProduct(['name' => 'Visita técnica', 'type' => 'servico', 'initial_stock' => '3']))['stock'] == 0, 'serviço ignora estoque inicial');
throws(fn() => Inventory::saveProduct(['name' => 'Neg', 'type' => 'produto', 'initial_stock' => '-1']), 'negativo', 'estoque inicial negativo');
Inventory::saveProduct(['name' => 'Válvula de alívio', 'type' => 'produto', 'initial_stock' => '9'], $ip);
ok(abs(Inventory::product($ip)['stock'] - 5) < 1e-6, 'na edição o estoque inicial é ignorado');

echo "\nImportação de produtos por planilha: ";
$raw = ProductImport::read(file_get_contents($fx . '/produtos-import.xlsx'));
ok(array_key_first($raw) === 2, 'pula o cabeçalho e mantém o número da linha');
$pv = ProductImport::preview($raw);
ok($pv['total'] === 6 && count($pv['rows']) === 4, 'prévia: 6 linhas preenchidas, 4 para cadastrar');
ok(count($pv['skipped']) === 1 && str_contains($pv['skipped'][0]['reason'], 'repetido') && count($pv['errors']) === 1, 'SKU repetido na planilha é pulado e linha inválida vira erro');
ok(str_contains($pv['errors'][0]['error'], 'negativo') && str_contains($pv['errors'][0]['error'], 'data'), 'erro diz o motivo (preço negativo, data inválida)');
$byS = array_column($pv['rows'], null, 'sku');
ok($byS['00003']['name'] === 'W 0179' && !empty($byS['00003']['noname']) && $byS['00003']['last_move'] === null, 'sem nome usa o código do fabricante; data 30/12/1899 = sem data');
ok(isset($byS['1615603800']) && $byS['1615603800']['last_move'] === '2026-11-10', 'SKU numérico e data no formato do Excel');
ok($byS['00002']['notes'] === "linha 1\nlinha 2" && $byS['00002']['price'] == 12.5, 'observações sem _x000D_ e preço decimal');
$res = ProductImport::import($pv['rows'], $uid);
ok($res['created'] === 4 && !$res['errors'], 'cadastra os 4 produtos');
$p2 = DB::one("SELECT * FROM products WHERE sku = '00002'");
$m2 = DB::all('SELECT * FROM movements WHERE product_id = ?', [$p2['id']]);
ok(abs($p2['stock'] - 3) < 1e-6 && abs($p2['sale_price'] - 12.5) < 1e-6 && (float)$p2['avg_cost'] == 0 && $p2['brand'] === 'ATLAS COPCO' && $p2['location'] === 'CX 01' && $p2['mfr_code'] === '0653 1101 00', 'estoque, preço de venda (coluna E, sem mexer no custo), marca, local e código do fabricante');
ok(count($m2) === 1 && $m2[0]['type'] === 'ajuste' && str_starts_with($m2[0]['created_at'], '2026-04-06') && (int)$m2[0]['user_id'] === $uid, 'estoque > 0: contagem na data da última movimentação');
$p1 = DB::one("SELECT * FROM products WHERE sku = '00001'");
$m1 = DB::all('SELECT * FROM movements WHERE product_id = ?', [$p1['id']]);
ok(count($m1) === 1 && $m1[0]['type'] === 'entrada' && (float)$m1[0]['quantity'] == 0 && str_starts_with($m1[0]['created_at'], date('Y-m-d')) && abs($p1['sale_price'] - 25) < 1e-6, 'estoque 0: só entrada (cadastro) na data da importação, com o preço de venda');
$m3 = DB::one("SELECT m.* FROM movements m JOIN products p ON p.id = m.product_id WHERE p.sku = '00003'");
ok($m3['type'] === 'ajuste' && str_starts_with($m3['created_at'], date('Y-m-d')), 'estoque > 0 sem data: contagem com a data de hoje');
$again = ProductImport::import($pv['rows'], $uid);
ok($again['created'] === 0 && count($again['skipped']) === 4, 'importar de novo pula os SKUs que já existem');
ok(count(ProductImport::preview($raw)['skipped']) === 5, 'prévia de novo mostra os já cadastrados como pulados');
$csv = ProductImport::preview(ProductImport::read("SKU;FAB;NOME;MARCA;VENDA;DATA;LOCAL;ESTOQUE;OBS\nC-1;AB 12;Correia;Gates;1.234,50;01/02/2025;A1;2;ok\n"));
ok(count($csv['rows']) === 1 && $csv['rows'][0]['price'] == 1234.5 && $csv['rows'][0]['last_move'] === '2025-02-01', 'também lê CSV com ponto e vírgula');

echo "\nDesfazer importação: ";
$hist = ProductImport::history();
ok(count($hist) === 1 && $hist[0]['day'] === date('Y-m-d') && $hist[0]['total'] === 4 && $hist[0]['used'] === 0, 'histórico mostra a importação de hoje (4 produtos)');
$used = (int)DB::val("SELECT id FROM products WHERE sku = '00002'");
Inventory::move($used, 'saida', 1, null, ['user_id' => $uid]);
$refP = (int)DB::val("SELECT id FROM products WHERE sku = '00001'");
DB::insert('product_refs', ['product_id' => $refP, 'manufacturer' => 'X', 'code' => 'R1', 'code_norm' => 'R1']);
$un = ProductImport::undo(date('Y-m-d'));
ok($un['deleted'] === 3 && count($un['kept']) === 1 && $un['kept'][0]['sku'] === '00002', 'apaga os não usados e mantém o que teve saída depois');
ok(!DB::val("SELECT id FROM products WHERE sku = '00001'") && !DB::val('SELECT COUNT(*) FROM product_refs WHERE product_id = ?', [$refP]) && !DB::val('SELECT COUNT(*) FROM movements WHERE product_id = ?', [$refP]), 'apaga o produto, o histórico e as referências');
ok(DB::val("SELECT id FROM products WHERE sku = '00002'") == $used && ProductImport::history()[0]['used'] === 1, 'o usado continua e aparece como usado');
throws(fn() => ProductImport::undo('2001-01-01'), 'Nenhum produto', 'dia sem importação');

echo "\nBackup no Google Drive: ";
// Simula o Apps Script: confere a chave, grava na "pasta" e manda os antigos para a lixeira
$driveFiles = []; $scriptKey = null; $calls = 0;
GDrive::$transport = function ($url, $d) use (&$driveFiles, &$scriptKey, &$calls) {
    $calls++;
    if ($url === 'https://script.google.com/macros/s/AKfycbNAOEXISTE000000000000/exec') return [404, '<html>Not Found</html>'];
    if ($d['chave'] !== $scriptKey) return [200, json_encode(['ok' => false, 'erro' => 'Chave inválida: copie o script de novo pelo sistema.'])];
    if ($d['pasta'] === '1SEMAUTORIZACAO00') return [200, json_encode(['ok' => false, 'erro' => 'Você não tem permissão para chamar DriveApp.getFolderById. Permissões necessárias: (https://www.googleapis.com/auth/drive.readonly || https://www.googleapis.com/auth/drive).'])];
    if ($d['pasta'] !== '1AbCdEfGhIjKlMnOp') return [200, json_encode(['ok' => false, 'erro' => 'No item with the given ID could be found'])];
    if ($d['acao'] === 'teste') return [200, json_encode(['ok' => true, 'pasta' => 'Backups Estoque', 'conta' => 'pedro@example.com'])];
    $n = count($driveFiles) + 1;
    $driveFiles[] = ['id' => "F$n", 'name' => $d['nome'], 'data' => base64_decode($d['dados'])];
    $mine = array_values(array_filter($driveFiles, fn($f) => empty($f['trashed']) && str_starts_with($f['name'], $d['prefixo'])));
    $rem = 0;
    if ($d['guardar'] > 0) foreach (array_slice(array_reverse($mine), $d['guardar']) as $old) { foreach ($driveFiles as &$f) if ($f['id'] === $old['id']) $f['trashed'] = true; $rem++; }
    return [200, json_encode(['ok' => true, 'id' => "F$n", 'removidos' => $rem])];
};
$st = GDrive::status();
$scriptKey = GDrive::key();
ok(strlen($scriptKey) === 48 && str_contains($st['script'], "const CHAVE = '$scriptKey'") && str_contains($st['script'], 'function doPost') && !$st['connected'], 'script pronto com a chave secreta deste sistema');
ok(GDrive::key() === $scriptKey, 'a chave não muda a cada vez');
ok(GDrive::folderId('https://drive.google.com/drive/folders/1AbCdEfGhIjKlMnOp?usp=sharing') === '1AbCdEfGhIjKlMnOp' && GDrive::folderId('https://drive.google.com/drive/u/0/folders/1AbCdEfGhIjKlMnOp') === '1AbCdEfGhIjKlMnOp', 'pega o ID da pasta pelo link');
throws(fn() => GDrive::folderId('minha pasta'), 'Link da pasta inválido', 'link de pasta inválido');
throws(fn() => GDrive::connect('https://exemplo.com/exec', '1AbCdEfGhIjKlMnOp'), 'Endereço do script inválido', 'endereço que não é do Apps Script');
throws(fn() => GDrive::connect('https://script.google.com/macros/s/AKfycbNAOEXISTE000000000000/exec', '1AbCdEfGhIjKlMnOp'), 'não encontrado', 'script inexistente');
$okUrl = 'https://script.google.com/macros/s/AKfycbTESTE0000000000000000/exec';
throws(fn() => GDrive::connect($okUrl, 'https://drive.google.com/drive/folders/1ZZZZZZZZZZZZZZ'), 'confira o link da pasta', 'pasta que o script não acessa');
throws(fn() => GDrive::connect($okUrl, '1SEMAUTORIZACAO00'), 'função "autorizar"', 'script sem autorização do Drive: explica como autorizar');
ok(str_contains(GDrive::script(), 'function autorizar()'), 'script traz a função autorizar');
ok(!GDrive::connected(), 'teste com erro não salva');
$scriptKey = 'outra'; throws(fn() => GDrive::connect($okUrl, '1AbCdEfGhIjKlMnOp'), 'Chave inválida', 'script com chave antiga é recusado'); $scriptKey = GDrive::key();
$st = GDrive::connect($okUrl, 'https://drive.google.com/drive/folders/1AbCdEfGhIjKlMnOp?usp=sharing');
ok($st['connected'] && $st['folder_name'] === 'Backups Estoque' && $st['account'] === 'pedro@example.com' && $st['folder_url'] === 'https://drive.google.com/drive/folders/1AbCdEfGhIjKlMnOp', 'testa e salva: pasta e conta');
ok(!array_key_exists('gdrive_key', Settings::all()), 'chave fica fora das configurações gerais');
GDrive::saveConfig(['freq' => 'daily', 'hour' => 3, 'keep' => 2], '2026-03-09 10:00:00');
ok(!GDrive::isDue('2026-03-10 02:59:00'), 'ligado às 10h: não envia na hora, espera as 3h do dia seguinte');
ok(GDrive::isDue('2026-03-10 03:05:00'), 'diário às 3h: chegou a hora');
ok(GDrive::nextAt('2026-03-10 02:00:00') === '2026-03-10 03:00:00', 'próximo backup às 3h');
$msg = GDrive::cron('2026-03-10 03:05:00');
$sql = gzdecode($driveFiles[0]['data']);
ok(str_contains($msg, 'backup-estoque-2026-03-10-030500.sql.gz') && str_contains($sql, 'INSERT INTO `products`'), 'agendador envia o .sql.gz com os dados');
ok(!str_contains($sql, $scriptKey) && !str_contains($sql, 'script.google.com') && str_contains($sql, "DELETE FROM `settings` WHERE SUBSTR(name, 1, 7) <> 'gdrive_'"), 'backup não leva a chave nem o endereço e não os apaga ao restaurar');
ok(GDrive::cron('2026-03-10 03:10:00') === '' && GDrive::cron('2026-03-11 02:00:00') === '', 'não repete no mesmo dia');
$driveFiles[] = ['id' => 'OUTRO', 'name' => 'planilha-do-pedro.xlsx', 'data' => ''];
GDrive::cron('2026-03-11 03:00:00'); $msg = GDrive::cron('2026-03-12 04:00:00');
$live = array_column(array_filter($driveFiles, fn($f) => empty($f['trashed'])), 'id');
ok($live === ['OUTRO', 'F3', 'F4'] && str_contains($msg, '1 antigo'), 'guarda só os últimos 2 backups e não mexe nos outros arquivos da pasta');
GDrive::saveConfig(['freq' => 'weekly', 'weekday' => 1, 'hour' => 22], '2026-03-12 05:00:00'); // segunda 22h
ok(!GDrive::isDue('2026-03-16 21:00:00') && GDrive::isDue('2026-03-16 22:01:00') && GDrive::nextAt('2026-03-14 10:00:00') === '2026-03-16 22:00:00', 'semanal: segunda às 22h');
$scriptKey = 'trocada';
$msg = GDrive::cron('2026-03-16 22:05:00');
ok(str_starts_with($msg, 'ERRO') && str_contains(GDrive::get('last_error'), 'Chave inválida'), 'erro do script fica registrado');
ok(GDrive::cron('2026-03-16 22:10:00') === '' && GDrive::isDue('2026-03-16 23:10:00'), 'tenta de novo só depois de uma hora');
GDrive::disconnect();
ok(!GDrive::connected() && GDrive::cron('2026-03-23 22:05:00') === '', 'desligado: agendador não envia');
GDrive::saveConfig(['freq' => 'off']);
GDrive::$transport = null;

echo "\nE-mail do usuário: ";
$ua = Auth::createUser(['name' => 'Ana', 'email' => 'ana@x.com', 'password' => 'senha-forte-1']);
$ub = Auth::createUser(['name' => 'Bia', 'email' => 'bia@x.com', 'password' => 'senha-forte-2']);
ok(Auth::cleanEmail('  Ana.Nova@X.com ', $ua) === 'ana.nova@x.com', 'novo e-mail vai em minúsculas e sem espaços');
ok(Auth::cleanEmail('ana@x.com', $ua) === 'ana@x.com', 'o próprio e-mail continua valendo');
throws(fn() => Auth::cleanEmail('bia@x.com', $ua), 'Já existe', 'não deixa usar o e-mail de outro usuário');
throws(fn() => Auth::cleanEmail('BIA@X.COM', $ua), 'Já existe', 'nem com letras maiúsculas');
throws(fn() => Auth::cleanEmail('ana@', $ua), 'E-mail inválido', 'recusa e-mail inválido');

echo "\nExcluir usuário: ";
$admA = Auth::createUser(['name' => 'Adm A', 'email' => 'adma@x.com', 'password' => 'senha-forte-1', 'role' => 'admin']);
DB::update('users', $admA, ['role' => 'admin']);
$admB = Auth::createUser(['name' => 'Adm B', 'email' => 'admb@x.com', 'password' => 'senha-forte-1']);
DB::update('users', $admB, ['role' => 'admin']);
$novo = Auth::createUser(['name' => 'Novo', 'email' => 'novo@x.com', 'password' => 'senha-forte-1']);
$usado = Auth::createUser(['name' => 'Usado', 'email' => 'usado@x.com', 'password' => 'senha-forte-1']);
$pu = Inventory::saveProduct(['name' => 'P usuário', 'type' => 'produto', 'unit' => 'un', 'sale_price' => 10]);
Inventory::move($pu, 'entrada', 1, 5, ['user_id' => $usado, 'note' => 't']);
ok(Auth::deleteUser($novo, $admA) === 'deleted' && !DB::val('SELECT id FROM users WHERE id = ?', [$novo]), 'quem nunca usou o sistema é apagado de vez');
ok(Auth::deleteUser($usado, $admA) === 'deactivated' && DB::val('SELECT active FROM users WHERE id = ?', [$usado]) == 0, 'quem tem registros só é desativado');
throws(fn() => Auth::deleteUser($admB, $admA), 'console do servidor', 'administrador não é excluído por outro pela tela');
throws(fn() => Auth::deleteUser($admA, $admA), 'a si mesmo', 'ninguém se exclui');
$rowA = DB::one('SELECT * FROM users WHERE id = ?', [$admA]); $rowB = DB::one('SELECT * FROM users WHERE id = ?', [$admB]);
throws(fn() => Auth::assertCanEdit($rowB, $rowA), 'console do servidor', 'administrador não altera dados de outro administrador');
Auth::assertCanEdit($rowA, $rowA); ok(true, 'administrador altera os próprios dados');
Auth::assertCanEdit(DB::one('SELECT * FROM users WHERE id = ?', [$usado]), $rowA); ok(true, 'administrador altera dados de membros');
ok(Auth::deleteUser($admB, 0, true) === 'deleted', 'pelo console o administrador é excluído');
echo "\nPortal do cliente: ";
$_SESSION = [];
$pc = Customers::save(['type' => 'pf', 'document' => '529.982.247-25', 'name' => 'Cliente Portal', 'phone' => '11988887777']);
$pOutro = Customers::save(['type' => 'pf', 'name' => 'Sem Documento']);
throws(fn() => Portal::invite($pOutro), 'CPF ou CNPJ', 'convite exige CPF/CNPJ (é o login)');
$inv = Portal::invite($pc);
ok(preg_match('/^[0-9a-f]{48}$/', $inv['token']) && !DB::val('SELECT 1 FROM customer_portal WHERE invite_hash = ?', [$inv['token']]), 'convite: o banco guarda só o hash do token');
$st = Portal::status($pc);
ok(!$st['active'] && $st['invite_pending'], 'situação: convite pendente');
ok(Portal::inviteCustomer('abc') === null && Portal::inviteCustomer(str_repeat('a', 48)) === null, 'token inválido não abre o convite');
throws(fn() => Portal::acceptInvite($inv['token'], 'curta', 'curta'), 'pelo menos', 'senha mínima');
throws(fn() => Portal::acceptInvite($inv['token'], 'senha-boa-1', 'senha-boa-2'), 'não são iguais', 'confirmação da senha');
$me = Portal::acceptInvite($inv['token'], 'senha-boa-1', 'senha-boa-1');
ok((int)$me['id'] === $pc && (int)$_SESSION['cid'] === $pc, 'aceita o convite, cria a senha e já entra');
ok(Portal::inviteCustomer($inv['token']) === null, 'o link de convite só vale uma vez');
$_SESSION = [];
throws(fn() => Portal::login('529.982.247-25', 'errada'), 'incorretos', 'senha errada');
ok((int)Portal::login('52998224725', 'senha-boa-1')['id'] === $pc, 'entra com CPF (com ou sem pontuação) e senha');
ok(Portal::status($pc)['active'] && Portal::status($pc)['last_login'], 'situação: acesso ativo, com último acesso');
throws(fn() => Portal::changePassword($pc, 'errada', 'nova-senha-1', 'nova-senha-1'), 'atual incorreta', 'trocar senha confere a atual');
Portal::changePassword($pc, 'senha-boa-1', 'nova-senha-1', 'nova-senha-1');
throws(fn() => Portal::login('52998224725', 'senha-boa-1'), 'incorretos', 'senha antiga deixa de valer');
// dados: só os do próprio cliente
$psv = Inventory::saveProduct(['name' => 'Serviço portal', 'type' => 'servico', 'unit' => 'un', 'sale_price' => 100]);
$qVis = Quotes::save(['target' => 'cliente', 'customer_id' => $pc, 'status' => 'enviado', 'items' => [['product_id' => $psv, 'quantity' => 1, 'unit_price' => 100]]], $uid);
$qRas = Quotes::save(['target' => 'cliente', 'customer_id' => $pc, 'status' => 'rascunho', 'items' => [['product_id' => $psv, 'quantity' => 1, 'unit_price' => 50]]], $uid);
$qOut = Quotes::save(['target' => 'cliente', 'customer_id' => $cli2, 'status' => 'enviado', 'items' => [['product_id' => $psv, 'quantity' => 1, 'unit_price' => 70]]], $uid);
$pq = array_column(Portal::quotes($pc), null, 'id');
ok(isset($pq[$qVis]) && !isset($pq[$qRas]) && !isset($pq[$qOut]), 'orçamentos: só os enviados e só os do cliente (rascunho e de outros ficam de fora)');
ok($pq[$qVis]['label'] === 'Aguardando sua aprovação' && $pq[$qVis]['public_token'], 'orçamento enviado aparece como "Aguardando sua aprovação", com o link de resposta');
$psale = Inventory::createSale(['customer_id' => $pc, 'payment_method' => 'pix', 'items' => [['product_id' => $psv, 'quantity' => 2]]], $uid);
$oSale = Inventory::createSale(['customer_id' => $cli2, 'payment_method' => 'pix', 'items' => [['product_id' => $psv, 'quantity' => 1]]], $uid);
ok(array_column(Portal::sales($pc), 'id') === [$psale], 'compras: só as do cliente');
ok(Portal::sale($pc, $oSale) === null && count(Portal::sale($pc, $psale)['items']) === 1, 'detalhe da compra: não abre compra de outro cliente');
DB::insert('financial_entries', ['type' => 'receber', 'status' => 'aberto', 'description' => 'Parcela teste', 'customer_id' => $pc, 'amount' => 80,
    'due_date' => date('Y-m-d', strtotime('-3 days')), 'installment' => 1, 'installments' => 1, 'created_at' => now(), 'updated_at' => now()]);
$sits = array_column(Portal::entries($pc), 'situation', 'description');
ok(($sits['Parcela teste'] ?? '') === 'vencido' && in_array('pago', $sits, true), 'parcelas: vencida e paga (venda à vista)');
throws(fn() => Portal::requestChange($pc, 'oi'), 'Escreva', 'pedido de correção precisa de texto');
Portal::requestChange($pc, 'Meu telefone mudou para (11) 97777-6666');
$rq = Portal::status($pc)['requests'];
ok(count($rq) === 1 && Portal::openRequests()[$pc] === 1, 'pedido de correção registrado e contado como em aberto');
Portal::resolveRequest((int)$rq[0]['id'], $uid);
ok(!isset(Portal::openRequests()[$pc]) && Portal::status($pc)['requests'][0]['resolved_at'], 'pedido marcado como resolvido');
DB::run('UPDATE customers SET active = 0 WHERE id = ?', [$pc]);
ok(Portal::customer() === null, 'cliente desativado perde o acesso na hora');
DB::run('UPDATE customers SET active = 1 WHERE id = ?', [$pc]);
Portal::revoke($pc);
throws(fn() => Portal::login('52998224725', 'nova-senha-1'), 'incorretos', 'remover acesso apaga a senha');
ok(!str_contains(Backup::sql(), 'nova-senha-1') && str_contains(Backup::sql(), 'INSERT INTO `customer_portal`'), 'backup inclui o portal (só hashes)');
$_SESSION = [];

echo "\nNúmeros: ";
near(Inventory::num('1.234,56'), 1234.56, '"1.234,56"');
near(Inventory::num('1234.56'), 1234.56, '"1234.56"');
near(Inventory::num('2.000'), 2000, '"2.000" (milhar)');
near(Inventory::num('1.250.000'), 1250000, '"1.250.000"');
near(Inventory::num('1.5'), 1.5, '"1.5"');
near(Inventory::num('-500,00'), -500, '"-500,00"');

echo "\nOrdens de serviço\n";
$osCli = Customers::save(['type' => 'pj', 'document' => '04.252.011/0001-10', 'name' => 'Cliente OS Ltda', 'trade_name' => 'Cliente OS']);
$osOutro = Customers::save(['type' => 'pj', 'document' => '60.746.948/0001-12', 'name' => 'Outro Cliente Ltda']);
throws(fn() => Equipments::save(['customer_id' => $osCli]), 'nome do equipamento', 'equipamento exige nome');
$eqA = Equipments::save(['name' => 'Compressor 5HP', 'customer_id' => $osCli, 'brand' => 'Schulz', 'serial' => 'SN-1']);
$eqB = Equipments::save(['name' => 'Compressor 2HP', 'customer_id' => $osOutro]);
ok(count(Equipments::list(['customer_id' => $osCli])) === 1, 'lista equipamentos por cliente');
Equipments::setActive($eqB, false);
ok(count(Equipments::list(['customer_id' => $osOutro])) === 0 && count(Equipments::list(['customer_id' => $osOutro, 'all' => 1])) === 1, 'inativo some da lista, aparece com "todos"');
Equipments::setActive($eqB, true);
$eqC = Equipments::save(['name' => 'Secador 3HP', 'customer_id' => $osCli, 'category' => 'Secadores', 'serial' => 'SEC-9',
    'valid_until' => '2030-05-31', 'warranty_until' => '2027-01-15',
    'specs' => [['label' => 'Potência', 'value' => '3 HP'], ['label' => '', 'value' => ''], ['label' => 'Voltagem', 'value' => '220 V']]]);
$gotC = Equipments::get($eqC);
ok($gotC['category'] === 'Secadores' && $gotC['valid_until'] === '2030-05-31' && $gotC['warranty_until'] === '2027-01-15', 'equipamento guarda categoria, validade e garantia');
ok(count($gotC['specs']) === 2 && $gotC['specs'][1] === ['label' => 'Voltagem', 'value' => '220 V'], 'especificações vazias somem e a lista volta em ordem');
$listC = Equipments::list(['customer_id' => $osCli]);
ok(isset($listC[0]['specs']) && is_array($listC[0]['specs']), 'lista devolve especificações como lista');
throws(fn() => Equipments::save(['name' => 'X', 'customer_id' => $osCli, 'valid_until' => '31/05/2030']), 'Data de validade inválida', 'data em formato errado recusada');
throws(fn() => Equipments::save(['name' => 'X', 'customer_id' => $osCli, 'warranty_until' => '2030-02-31']), 'Garantia inválida', 'data que não existe recusada');
throws(fn() => Equipments::save(['name' => 'X', 'customer_id' => $osCli, 'specs' => [['label' => '', 'value' => '10']]]), 'nome de cada especificação', 'valor sem nome de especificação recusado');
Equipments::save(['name' => 'Secador 3HP', 'customer_id' => $osCli, 'warranty_until' => ''], $eqC);
ok(Equipments::get($eqC)['warranty_until'] === null && Equipments::get($eqC)['specs'] === [], 'edição limpa datas e especificações vazias');

echo "\nQuestionários e tipos de tarefa\n";
$qFull = Questionnaires::save(['name' => 'Manutenção compressor', 'show_os' => 1, 'per_line' => 2, 'questions' => [
    ['label' => 'Relato antes', 'type' => 'texto', 'required' => true],
    ['label' => 'Fotos antes', 'type' => 'foto', 'geo' => true],
    ['label' => 'Situação', 'type' => 'itens', 'options' => "Funcionando\n\nCom defeito\nFuncionando", 'required' => true],
    ['label' => 'Motivo do defeito', 'type' => 'area', 'cond' => ['question' => 'p3', 'value' => 'Com defeito']],
    ['label' => 'Valor da peça', 'type' => 'monetaria'],
]]);
$qg = Questionnaires::get($qFull);
ok(count($qg['questions']) === 5 && $qg['questions'][0]['id'] === 'p1' && $qg['questions_count'] === 5, 'questionário guarda as perguntas na ordem');
ok($qg['questions'][2]['options'] === ['Funcionando', 'Com defeito'], 'opções: vazias e repetidas somem');
ok($qg['questions'][1]['geo'] === true && $qg['questions'][3]['cond'] === ['question' => 'p3', 'op' => 'igual', 'value' => 'Com defeito'], 'foto com localização e condição (Igual por padrão) são guardadas');
$cBase = [['label' => 'Situação', 'type' => 'itens', 'options' => "Funcionando\nCom defeito"], ['label' => 'Obs', 'type' => 'texto']];
throws(fn() => Questionnaires::save(['name' => 'C', 'questions' => [...$cBase, ['label' => 'Depende', 'type' => 'texto', 'cond' => ['question' => 'p2', 'value' => 'x']]]]), 'não suporta condições', 'texto não pode ser usado em "Apresentar quando"');
throws(fn() => Questionnaires::save(['name' => 'C', 'questions' => [...$cBase, ['label' => 'Depende', 'type' => 'texto', 'cond' => ['question' => 'p1', 'value' => 'Quebrado']]]]), 'preencha "Resposta esperada"', 'resposta esperada precisa ser uma opção da pergunta');
throws(fn() => Questionnaires::save(['name' => 'C', 'questions' => [...$cBase, ['label' => 'Depende', 'type' => 'texto', 'cond' => ['question' => 'p1', 'op' => 'maior', 'value' => 'Funcionando']]]]), 'preencha "Condição"', 'Itens só aceita Igual ou Diferente');
$qDif = Questionnaires::get(Questionnaires::save(['name' => 'C', 'questions' => [...$cBase, ['label' => 'Por quê?', 'type' => 'texto', 'cond' => ['question' => 'p1', 'op' => 'diferente', 'value' => 'Funcionando']],
    ['label' => 'Detalhe', 'type' => 'texto', 'cond' => ['question' => 'p1', 'op' => 'igual', 'value' => 'Com defeito']]]]))['questions'];
$vm = fn($a) => array_keys(array_filter(OrderExec::visibleMap($qDif, $a)));
ok($vm([]) === ['p1', 'p2'], 'condicional não aparece enquanto a pergunta de origem não foi respondida (Igual e Diferente)');
ok($vm(['p1' => 'Com defeito']) === ['p1', 'p2', 'p3', 'p4'], 'Diferente de "Funcionando" e Igual a "Com defeito" aparecem');
ok($vm(['p1' => 'Funcionando']) === ['p1', 'p2'], 'resposta igual à esperada esconde a condicional "Diferente"');
// Condições por tipo da pergunta de origem (Numérica, Monetária, Data, Hora, Check e Itens com múltipla escolha)
$tq = fn($type, $op, $value, $extra = []) => Questionnaires::get(Questionnaires::save(['name' => 'T', 'questions' => [
    ['id' => 'm', 'label' => 'Mãe', 'type' => $type] + $extra, ['id' => 'c', 'label' => 'Filha', 'type' => 'texto', 'cond' => ['question' => 'm', 'op' => $op, 'value' => $value]]]]))['questions'];
$shows = fn($qs, $ans) => OrderExec::visibleMap($qs, ['m' => $ans])['c'];
$qn = $tq('numerica', 'maior_igual', '10,5');
ok($qn[1]['cond']['value'] === '10.5' && $shows($qn, '10,5') && $shows($qn, '11') && !$shows($qn, '10') && !$shows($qn, ''), 'Numérica: Maior ou igual (vírgula aceita, sem resposta esconde)');
ok($shows($tq('monetaria', 'menor', 'R$ 1.000,00'), '999.99') && !$shows($tq('monetaria', 'menor', '1000'), '1000'), 'Monetária: Menor');
$qd = $tq('data', 'maior', '01/12/2026');
ok($qd[1]['cond']['value'] === '2026-12-01' && $shows($qd, '2026-12-02') && !$shows($qd, '2026-12-01'), 'Data: Maior (dd/mm/aaaa vira aaaa-mm-dd)');
ok($shows($tq('hora', 'diferente', '8:00'), '09:00') && !$shows($tq('hora', 'diferente', '08:00'), '08:00'), 'Hora: Diferente');
$qc = $tq('check', 'igual', 'Falso');
ok($qc[1]['cond']['value'] === 'nao' && $shows($qc, '') && !$shows($qc, 'Sim') && $shows($tq('check', 'igual', 'Verdadeiro'), 'Sim'), 'Check: Igual a Verdadeiro/Falso (sem marcar = Falso)');
throws(fn() => $tq('check', 'diferente', 'sim'), 'preencha "Condição"', 'Check só aceita Igual');
$mx = ['options' => "A\nB\nC"];
$qm = $tq('multipla', 'contem_todos', ['A', 'B'], $mx);
ok($qm[1]['cond']['value'] === ['A', 'B'] && $shows($qm, ['A', 'B', 'C']) && !$shows($qm, ['A']), 'Múltipla: Contém todos');
ok($shows($tq('multipla', 'contem_um', ['A', 'B'], $mx), ['B']) && !$shows($tq('multipla', 'contem_um', ['A', 'B'], $mx), ['C']), 'Múltipla: Contém um');
ok($shows($tq('multipla', 'nao_contem_um', ['A', 'B'], $mx), ['C']) && !$shows($tq('multipla', 'nao_contem_um', ['A', 'B'], $mx), ['B', 'C']) && !$shows($tq('multipla', 'nao_contem_um', ['A'], $mx), []), 'Múltipla: Não contém um (sem resposta esconde)');
throws(fn() => $tq('multipla', 'igual', ['A'], $mx), 'preencha "Condição"', 'Múltipla não aceita Igual');
throws(fn() => $tq('multipla', 'contem_um', ['Z'], $mx), 'preencha "Resposta esperada"', 'Múltipla: itens esperados precisam estar nas opções');
foreach (['texto', 'area', 'documento', 'assinatura', 'foto'] as $bad) throws(fn() => $tq($bad, 'igual', 'x'), 'não suporta condições', "$bad não pode ser pergunta de origem");
throws(fn() => Questionnaires::save(['name' => 'T', 'questions' => [['id' => 'c', 'label' => 'Filha', 'type' => 'texto', 'cond' => ['question' => 'm', 'op' => 'igual', 'value' => 'Sim']], ['id' => 'm', 'label' => 'Mãe', 'type' => 'itens', 'options' => "Sim\nNão"]]]),
    'A pergunta 1 depende da pergunta 2, que está depois dela', 'pergunta mãe depois da condicional é recusada');
$qch = Questionnaires::get(Questionnaires::save(['name' => 'Cadeia', 'questions' => [
    ['id' => 'a', 'label' => 'Liga?', 'type' => 'itens', 'options' => "Sim\nNão"],
    ['id' => 'b', 'label' => 'Temperatura', 'type' => 'numerica', 'cond' => ['question' => 'a', 'op' => 'igual', 'value' => 'Sim']],
    ['id' => 'c', 'label' => 'Por que quente?', 'type' => 'texto', 'cond' => ['question' => 'b', 'op' => 'maior', 'value' => '80']]]]))['questions'];
$chv = fn($a) => array_keys(array_filter(OrderExec::visibleMap($qch, $a)));
ok($chv(['a' => 'Sim', 'b' => '90']) === ['a', 'b', 'c'] && $chv(['a' => 'Não', 'b' => '90']) === ['a'], 'pergunta condicional também pode ser a pergunta de origem de outra (em cadeia)');
ok(OrderExec::visibleMap([['id' => 'm', 'type' => 'multipla', 'options' => ['A', 'B']], ['id' => 'c', 'type' => 'texto', 'cond' => ['question' => 'm', 'value' => 'A']]], ['m' => ['A']])['c'], 'condição antiga sem "op" em múltipla vale como Contém um');
throws(fn() => Questionnaires::save(['name' => 'Sem perguntas', 'questions' => []]), 'pelo menos uma pergunta', 'questionário exige pergunta');
throws(fn() => Questionnaires::save(['name' => 'Tipo', 'questions' => [['label' => 'x', 'type' => 'xyz']]]), 'Tipo de resposta inválido', 'tipo de resposta validado');
throws(fn() => Questionnaires::save(['name' => 'Opções', 'questions' => [['label' => 'Escolha', 'type' => 'multipla', 'options' => '']]]), 'opções', 'pergunta de itens exige opções');
throws(fn() => Questionnaires::save(['name' => 'Cond', 'questions' => [['label' => 'Depende', 'type' => 'texto', 'cond' => ['question' => 'p9', 'value' => 'x']]]]), 'preencha "Apresentar quando"', 'condição precisa de pergunta anterior');
throws(fn() => Questionnaires::save(['name' => 'Linha', 'per_line' => 5, 'questions' => [['label' => 'x', 'type' => 'texto']]]), 'de 1 a 4', 'perguntas por linha de 1 a 4');

$ttFull = TaskTypes::save(['name' => 'Preventiva mensal', 'tolerance' => '01:30', 'duration' => '2:15', 'questionnaire_ids' => [$qFull], 'requires' => ['relato', 'questionario', 'assinatura'], 'send_survey' => 1]);
$tt = TaskTypes::get($ttFull);
ok($tt['tolerance_min'] === 90 && $tt['duration'] === '02:15' && $tt['tolerance'] === '01:30', 'tipo de tarefa: tempos em minutos e de volta em HH:MM');
ok($tt['questionnaire_ids'] === [$qFull] && in_array('questionario', $tt['requires'], true) && $tt['send_survey'] === 1, 'tipo de tarefa guarda questionários, obrigatoriedades e envio');
throws(fn() => TaskTypes::save(['name' => 'Sem questionário', 'requires' => ['questionario']]), 'pelo menos um questionário', 'exigir questionário pede um questionário');
throws(fn() => TaskTypes::save(['name' => 'Tempo', 'duration' => '12:7']), 'HH:MM', 'tempo fora do formato recusado');
throws(fn() => TaskTypes::save(['name' => 'Obrig', 'requires' => ['xyz']]), 'Obrigatoriedade inválida', 'obrigatoriedade validada');
throws(fn() => TaskTypes::save(['name' => '  ']), 'Informe', 'tipo sem nome recusado');
Questionnaires::setActive($qFull, false);
throws(fn() => TaskTypes::save(['name' => 'Inativo', 'questionnaire_ids' => [$qFull]]), 'inativo', 'questionário inativo não entra em tipo novo');
Questionnaires::setActive($qFull, true);
ok(TaskTypes::names()[$ttFull] === 'Preventiva mensal', 'nomes dos tipos para as listas de OS');
ok(count(TaskTypes::list(false)) >= 6, 'tipos padrão da atualização existem');
DB::run("INSERT INTO checklist_models (name, service_type, items, active, created_at, updated_at) VALUES (?, ?, ?, 1, ?, ?)",
    ['Legado teste', 'visita', json_encode([['label' => 'Verificar ruído', 'required' => true]]), now(), now()]);
TaskTypes::importLegacy();
$legacy = array_values(array_filter(Questionnaires::list(true), fn($q) => $q['name'] === 'Legado teste'));
ok(count($legacy) === 1 && $legacy[0]['questions'][0]['type'] === 'check' && $legacy[0]['questions'][0]['required'] === true, 'checklist antigo vira questionário com perguntas de check');
$visitas = array_filter(TaskTypes::list(true), fn($t) => $t['name'] === 'Visita técnica');
ok(count(array_filter($visitas, fn($t) => in_array($legacy[0]['id'], $t['questionnaire_ids']))) >= 1, 'tipo "Visita técnica" aponta para o questionário do checklist antigo');

throws(fn() => ServiceOrders::save(['customer_id' => $osCli, 'description' => 'Troca de filtro', 'equipment_id' => $eqB], $uid), 'não pertence', 'equipamento de outro cliente recusado');
throws(fn() => ServiceOrders::save(['customer_id' => $osCli, 'description' => 'Troca de filtro', 'origin' => 'orcamento', 'quote_id' => 999], $uid), 'orçamento deste cliente', 'orçamento precisa ser do cliente');
throws(fn() => ServiceOrders::save(['customer_id' => $osCli, 'description' => '  '], $uid), 'Descreva', 'descrição obrigatória');
$osAvulsa = ServiceOrders::save(['customer_id' => $osCli, 'description' => 'Compressor não liga', 'equipment_id' => $eqA, 'origin' => 'chamado', 'task_type_id' => TaskTypes::names() ? array_key_first(TaskTypes::names()) : null], $uid);
$osA = ServiceOrders::get($osAvulsa);
ok($osA['status'] === 'aberta' && $osA['origin'] === 'chamado' && $osA['equipment_name'] === 'Compressor 5HP', 'chamado avulso nasce aberta, com equipamento');
$osAg = ServiceOrders::save(['customer_id' => $osCli, 'description' => 'Preventiva mensal', 'scheduled_at' => date('Y-m-d', strtotime('+2 days')) . 'T08:30', 'technician_id' => $uid, 'task_type_id' => $ttFull], $uid);
$osAgR = ServiceOrders::get($osAg);
ok($osAgR['status'] === 'agendada' && $osAgR['scheduled_at'] !== null && (int)$osAgR['technician_id'] === $uid, 'com data vira agendada, com técnico e tipo de tarefa');
ServiceOrders::save(['customer_id' => $osCli, 'description' => 'Preventiva mensal', 'scheduled_at' => '', 'task_type_id' => $ttFull], $uid, $osAg);
ok(ServiceOrders::get($osAg)['status'] === 'aberta', 'tirar a data volta para aberta');
ServiceOrders::save(['customer_id' => $osCli, 'description' => 'Preventiva mensal', 'scheduled_at' => date('Y-m-d H:i', strtotime('+2 days'))], $uid, $osAg);

throws(fn() => ServiceOrders::setStatus($osAvulsa, 'em_execucao'), 'Não é possível', 'não pula de aberta para execução');
ServiceOrders::setStatus($osAvulsa, 'agendada');
ServiceOrders::setStatus($osAvulsa, 'em_execucao');
ServiceOrders::setStatus($osAvulsa, 'pausada');
ServiceOrders::setStatus($osAvulsa, 'em_execucao');
ServiceOrders::setStatus($osAvulsa, 'concluida');
ok(ServiceOrders::get($osAvulsa)['finished_at'] !== null, 'concluir grava a data de término');
throws(fn() => ServiceOrders::setStatus($osAvulsa, 'faturada'), 'Status inválido', 'OS não tem status "faturada" (quem fatura é o orçamento)');
throws(fn() => ServiceOrders::setStatus($osAvulsa, 'aberta'), 'Não é possível', 'concluída é o fim do fluxo');
ok(ServiceOrders::get(ServiceOrders::save(['customer_id' => $osCli, 'description' => 'Ajuste depois de concluir', 'equipment_id' => $eqA, 'origin' => 'chamado', 'task_type_id' => ServiceOrders::get($osAvulsa)['task_type_id']], $uid, $osAvulsa))['description'] === 'Ajuste depois de concluir', 'OS concluída ainda pode ser corrigida');
$abertas = array_column(ServiceOrders::list(['status' => 'abertas']), 'id');
ok(in_array($osAg, $abertas) && !in_array($osAvulsa, $abertas), 'aba "abertas" não mostra concluídas');
ok(count(ServiceOrders::list(['status' => 'concluida'])) >= 1, 'filtro por status funciona');
$todasCli = ServiceOrders::list(['customer_id' => $osCli, 'status' => 'todas']);
ok(count($todasCli) >= 2 && count(array_filter($todasCli, fn($r) => (int)$r['customer_id'] !== (int)$osCli)) === 0, 'histórico do cliente traz todas as ordens dele, inclusive concluídas');
ok(count(ServiceOrders::list(['customer_id' => $osOutro, 'status' => 'todas'])) === 0, 'cliente sem ordens não traz nada');
$histEq = ServiceOrders::list(['equipment_id' => $eqA, 'status' => 'todas']);
ok(in_array($osAvulsa, array_column($histEq, 'id')), 'histórico do equipamento traz as ordens dele');
ok(count(array_filter($histEq, fn($r) => (int)$r['equipment_id'] !== (int)$eqA)) === 0, 'histórico do equipamento não traz ordens de outro equipamento');
ok(array_key_exists('description', $todasCli[0]), 'lista de ordens traz a descrição');
ok(str_contains(Backup::sql(), 'INSERT INTO `service_orders`'), 'backup inclui ordens de serviço');
$tecProfile = DB::one("SELECT permissions FROM permission_profiles WHERE name = 'Técnico'");
ok($tecProfile && $tecProfile['permissions'] === 'ordens', 'perfil Técnico só tem ordens de serviço');
$tecId = Auth::createUser(['name' => 'Técnico Teste', 'email' => 'tecnico@example.com', 'password' => 'senha12345', 'profile_id' => (int)DB::val("SELECT id FROM permission_profiles WHERE name = 'Técnico'")]);
$tecUser = DB::one('SELECT u.*, p.permissions profile_permissions FROM users u LEFT JOIN permission_profiles p ON p.id = u.profile_id WHERE u.id = ?', [$tecId]);
ok(Auth::permsOf($tecUser) === ['ordens'], 'usuário com perfil Técnico recebe só a permissão de ordens');
ok(in_array($tecId, array_map('intval', array_column(DB::all("SELECT id FROM users WHERE active = 1 ORDER BY name"), 'id'))), 'técnico aparece na lista de técnicos');

echo "\nAgenda\n";
$ag1 = ServiceOrders::save(['customer_id' => $cf, 'description' => 'Agenda A', 'scheduled_at' => '2030-03-10T09:00', 'technician_id' => $uid], $uid);
$ag2 = ServiceOrders::save(['customer_id' => $cf, 'description' => 'Agenda B', 'scheduled_at' => '2030-03-10T14:30', 'technician_id' => $tecId], $uid);
$ag3 = ServiceOrders::save(['customer_id' => $cf, 'description' => 'Sem data'], $uid);
$ag4 = ServiceOrders::save(['customer_id' => $cf, 'description' => 'Fora do dia', 'scheduled_at' => '2030-03-12T10:00'], $uid);
$ag = ServiceOrders::agenda(['from' => '2030-03-10', 'to' => '2030-03-10']);
$agIds = array_map('intval', array_column($ag['rows'], 'id'));
ok($agIds === [$ag1, $ag2], 'agenda traz só as OS do dia, em ordem de horário');
ok(in_array($ag3, array_map('intval', array_column($ag['unscheduled'], 'id')), true), 'OS sem data entra em "sem agendamento"');
ok(!in_array($ag3, $agIds, true) && !in_array($ag4, array_map('intval', array_column($ag['unscheduled'], 'id')), true), 'OS sem data não aparece no calendário e OS de outro dia não entra no dia');
ok(ServiceOrders::agenda(['from' => '2030-03-10', 'to' => '2030-03-12'])['rows'][2]['id'] == $ag4, 'período de três dias inclui as três OS agendadas');
$agTec = ServiceOrders::agenda(['from' => '2030-03-10', 'to' => '2030-03-10', 'tech' => $tecId]);
ok(count($agTec['rows']) === 1 && (int)$agTec['rows'][0]['id'] === $ag2, 'filtro por técnico');
ok(isset($ag['rows'][0]['status_label']) && $ag['rows'][0]['status_label'] === 'Agendada', 'linha da agenda traz o nome do status');
ok(isset($ag['counts']) && $ag['counts']['sem_agendamento'] >= 1, 'contagem de OS sem agendamento');
ok(isset($ag['technicians'][0]['name']) && isset($ag['task_types']), 'agenda devolve técnicos e tipos de tarefa para os filtros');
throws(fn() => ServiceOrders::agenda(['from' => '10/03/2030', 'to' => '2030-03-10']), 'Período inicial inválido', 'data inicial inválida recusada');
throws(fn() => ServiceOrders::agenda(['from' => '2030-03-10', 'to' => '2030-03-01']), 'anterior', 'período invertido recusado');
throws(fn() => ServiceOrders::agenda(['from' => '2030-01-01', 'to' => '2030-12-31']), 'longo demais', 'período de um ano recusado');
ServiceOrders::reschedule($ag3, '2030-03-11 08:15', $uid);
$r3 = ServiceOrders::get($ag3);
ok($r3['status'] === 'agendada' && $r3['scheduled_at'] === '2030-03-11 08:15:00' && (int)$r3['technician_id'] === $uid, 'arrastar OS aberta para o dia agenda e muda o status');
ServiceOrders::reschedule($ag2, '2030-03-10T16:00', $tecId);
ok(ServiceOrders::get($ag2)['scheduled_at'] === '2030-03-10 16:00:00', 'reagendar muda o horário (formato com T)');
ServiceOrders::reschedule($ag3, '', null);
$r3b = ServiceOrders::get($ag3);
ok($r3b['status'] === 'aberta' && $r3b['scheduled_at'] === null, 'sem horário a OS volta para aberta');
throws(fn() => ServiceOrders::reschedule($ag3, 'amanhã às 9', null), 'inválidos', 'data inválida recusada');
ServiceOrders::setStatus($ag4, 'em_execucao');
throws(fn() => ServiceOrders::reschedule($ag4, '2030-03-20 09:00', null), 'abertas ou agendadas', 'OS em execução não pode ser reagendada');
throws(fn() => ServiceOrders::reschedule($ag1, '2030-03-20 09:00', 999999), 'Técnico não encontrado', 'técnico inexistente recusado');

$agLate = ServiceOrders::save(['customer_id' => $cf, 'description' => 'Atrasada', 'scheduled_at' => '2020-01-05T08:00'], $uid);
$lateRows = array_map('intval', array_column(ServiceOrders::agenda(['from' => '2030-03-10', 'to' => '2030-03-10', 'late' => true])['rows'], 'id'));
ok(in_array($agLate, $lateRows, true) && !in_array($ag1, $lateRows, true), 'filtro "atrasadas" traz agendadas vencidas de qualquer data, sem as futuras');

echo "\nExecução da OS\n";
$mails = [];
Mailer::$transport = function ($to, $subject, $html) use (&$mails) { $mails[] = [$to, $subject, $html]; return true; };
$exCli = Customers::save(['type' => 'pf', 'name' => 'Cliente Execução', 'email' => 'cliente@example.com']);
$exQ = Questionnaires::save(['name' => 'Checklist execução', 'questions' => [
    ['id' => 'liga', 'label' => 'Equipamento liga?', 'type' => 'itens', 'options' => "Sim\nNão", 'required' => true],
    ['id' => 'motivo', 'label' => 'Motivo', 'type' => 'texto', 'required' => true, 'cond' => ['question' => 'liga', 'value' => 'Não']],
    ['id' => 'pressao', 'label' => 'Pressão', 'type' => 'numerica'],
    ['id' => 'ok', 'label' => 'Conferido', 'type' => 'check'],
    ['id' => 'pecas', 'label' => 'Peças', 'type' => 'multipla', 'options' => "Filtro\nÓleo"],
]]);
$exTT = TaskTypes::save(['name' => 'Execução completa', 'questionnaire_ids' => [$exQ], 'requires' => ['relato', 'questionario', 'assinatura', 'assinante_nome', 'fotos', 'km', 'servico'], 'send_survey' => 1, 'send_os_email' => 1]);
$exOs = ServiceOrders::save(['customer_id' => $exCli, 'description' => 'Revisão completa', 'task_type_id' => $exTT], $uid);
$exO = ServiceOrders::get($exOs);
ok(strlen($exO['public_token']) === 32, 'OS nova já tem link público');
ok(count(OrderExec::hours($exOs)['history']) === 1 && OrderExec::hours($exOs)['rows'] === [] && OrderExec::hours($exOs)['next'] === ['deslocamento', 'checkin'], 'criação entra no histórico; o controle de horas começa vazio, com deslocamento ou check-in');
$qs = OrderExec::questionnaires($exO);
ok(count($qs) === 1 && $qs[0]['linked'] && !$qs[0]['answered'], 'OS traz o questionário do tipo de tarefa, ainda sem resposta');
$p0 = OrderExec::pending($exO);
ok(count($p0) === 7, 'pendências: relato, questionário, assinatura, nome, foto, km e serviço (' . count($p0) . ')');
ServiceOrders::setStatus($exOs, 'agendada', $uid);
ServiceOrders::setStatus($exOs, 'em_execucao', $uid);
throws(fn() => ServiceOrders::setStatus($exOs, 'concluida', $uid), 'Para concluir falta', 'concluir com pendências é bloqueado');
ok(ServiceOrders::get($exOs)['started_at'] !== null, 'check-in grava o início do atendimento');
// respostas
$r = OrderExec::saveAnswers($exOs, $exQ, ['liga' => 'Não', 'pressao' => '7,5', 'ok' => true, 'pecas' => ['Óleo', 'Filtro', 'Óleo']], null, $uid);
ok($r['missing'] === ['Motivo'], 'pergunta condicional obrigatória passa a faltar quando a condição é atendida');
$ans = OrderExec::questionnaires(ServiceOrders::get($exOs))[0]['answers'];
ok($ans['pressao'] === '7.5' && $ans['ok'] === 'Sim' && $ans['pecas'] === ['Óleo', 'Filtro'], 'respostas normalizadas (número, check, múltipla sem repetir)');
$r = OrderExec::saveAnswers($exOs, $exQ, ['liga' => 'Sim', 'motivo' => 'texto escondido'], null, $uid);
$ans = OrderExec::questionnaires(ServiceOrders::get($exOs))[0]['answers'];
ok($r['missing'] === [] && !isset($ans['motivo']), 'resposta de pergunta escondida é descartada');
throws(fn() => OrderExec::saveAnswers($exOs, $exQ, ['liga' => 'Talvez'], null, $uid), 'Resposta inválida', 'opção fora da lista recusada');
throws(fn() => OrderExec::saveAnswers($exOs, $exQ, ['pressao' => 'alta'], null, $uid), 'Resposta inválida', 'número inválido recusado');
throws(fn() => OrderExec::saveAnswers($exOs, $q1 ?? 999999, [], null, $uid), 'não faz parte', 'questionário de fora da OS recusado');
// relato, assinatura, fotos, itens
$png = 'data:image/png;base64,' . base64_encode('fake-png');
OrderExec::saveReport($exOs, ['report' => 'Troquei o filtro', 'signer_name' => 'João', 'km' => '12,5', 'signature' => $png]);
$exO = ServiceOrders::get($exOs);
ok($exO['report'] === 'Troquei o filtro' && (float)$exO['km'] === 12.5 && $exO['signature'] === $png, 'relato, km e assinatura salvos');
OrderExec::saveReport($exOs, ['report' => 'Troquei o filtro', 'signer_name' => 'João', 'km' => '12,5']);
ok(ServiceOrders::get($exOs)['signature'] === $png, 'salvar sem a assinatura mantém a atual');
throws(fn() => OrderExec::saveReport($exOs, ['signer_doc' => '123']), 'CPF/CNPJ', 'documento do assinante inválido recusado');
throws(fn() => OrderExec::addPhoto($exOs, 'data:text/html;base64,AAAA', '', $uid), 'imagem inválida', 'foto que não é imagem recusada');
$ph = OrderExec::addPhoto($exOs, $png, 'Antes', $uid);
$svc = DB::val("SELECT id FROM products WHERE type = 'servico' AND deleted_at IS NULL LIMIT 1") ?: Inventory::saveProduct(['type' => 'servico', 'name' => 'Mão de obra', 'unit' => 'un', 'sale_price' => '100']);
throws(fn() => OrderExec::saveItem($exOs, ['kind' => 'produto', 'product_id' => $svc], $uid), 'Escolha um produto', 'serviço não entra como produto');
OrderExec::saveItem($exOs, ['kind' => 'servico', 'product_id' => $svc, 'quantity' => '2', 'unit_price' => '150'], $uid);
OrderExec::saveItem($exOs, ['kind' => 'custo', 'description' => 'Deslocamento', 'unit_price' => '40,50'], $uid);
$it = OrderExec::items($exOs);
ok($it['totals']['servico'] == 300 && $it['totals']['custo'] == 40.5 && $it['totals']['total'] == 340.5, 'totais dos valores por tipo');
ok(OrderExec::pending(ServiceOrders::get($exOs)) === [], 'sem pendências depois de tudo preenchido');
// pausa, conclusão, envios automáticos
ServiceOrders::setStatus($exOs, 'pausada', $uid);
ServiceOrders::setStatus($exOs, 'em_execucao', $uid);
ServiceOrders::setStatus($exOs, 'concluida', $uid);
$h = OrderExec::hours($exOs);
ok(array_column($h['rows'], 'label') === ['Check-in', 'Início da pausa', 'Fim da pausa', 'Check-out'] && $h['running'] === null && $h['next'] === [], 'controle de horas: check-in, pausa e check-out (abertura e agendamento só no histórico)');
ok(count($mails) === 2 && str_contains($mails[0][2], 'os.php?t=') && str_contains($mails[1][2], 'pesquisa=1') && $mails[0][0] === 'cliente@example.com', 'ao concluir, envia a OS digital e a pesquisa ao e-mail do cliente');
ok((int)DB::val('SELECT COUNT(*) FROM so_sends WHERE order_id = ? AND ok = 1', [$exOs]) === 2, 'envios registrados');
throws(fn() => OrderExec::send($exOs, 'os', 'nao-e-email', $uid), 'E-mail', 'envio manual para e-mail inválido mostra o erro');
ok((int)DB::val('SELECT COUNT(*) FROM so_sends WHERE order_id = ? AND ok = 0', [$exOs]) === 1, 'falha de envio também fica registrada');
// pesquisa
$tok = ServiceOrders::get($exOs)['public_token'];
throws(fn() => OrderExec::surveyRespond($tok, 9, ''), 'nota de 1 a 5', 'nota fora da escala recusada');
OrderExec::surveyRespond($tok, 5, 'Ótimo atendimento');
ok((int)ServiceOrders::get($exOs)['survey_rating'] === 5, 'avaliação do cliente gravada');
throws(fn() => OrderExec::surveyRespond($tok, 4, ''), 'já foi avaliada', 'não avalia duas vezes');
throws(fn() => OrderExec::byToken('abc'), 'não encontrada', 'link público inválido');
ok(OrderExec::byToken($tok)['order']['id'] == $exOs, 'link público abre a OS');
// pesquisa antes de concluir e envio automático com falha não bloqueia
$exOs2 = ServiceOrders::save(['customer_id' => $cf, 'description' => 'Sem e-mail'], $uid);
throws(fn() => OrderExec::send($exOs2, 'pesquisa', 'x@example.com', $uid), 'depois que a ordem é concluída', 'pesquisa só depois de concluir');
$failTT = TaskTypes::save(['name' => 'Só envia OS', 'send_os_email' => 1]);
$exOs3 = ServiceOrders::save(['customer_id' => $cf, 'description' => 'Cliente sem e-mail', 'task_type_id' => $failTT], $uid);
ServiceOrders::setStatus($exOs3, 'agendada', $uid); ServiceOrders::setStatus($exOs3, 'em_execucao', $uid); ServiceOrders::setStatus($exOs3, 'concluida', $uid);
ok(ServiceOrders::get($exOs3)['status'] === 'concluida' && (int)DB::val('SELECT ok FROM so_sends WHERE order_id = ?', [$exOs3]) === 0, 'cliente sem e-mail: conclui e registra o envio que falhou');
OrderExec::saveReport($exOs, ['report' => 'Relato corrigido depois de concluir', 'signer_name' => 'João', 'km' => '12,5']);
ok(ServiceOrders::get($exOs)['report'] === 'Relato corrigido depois de concluir', 'relato da OS concluída ainda pode ser corrigido');
ok(str_contains(Backup::sql(), 'INSERT INTO `so_answers`'), 'backup inclui as respostas');
Mailer::$transport = null;

echo "\nAjuste do controle de horas\n";
$hOs = ServiceOrders::save(['customer_id' => $cf, 'description' => 'Horas'], $uid);
ServiceOrders::setStatus($hOs, 'agendada', $uid); ServiceOrders::setStatus($hOs, 'a_caminho', $uid); ServiceOrders::setStatus($hOs, 'em_execucao', $uid);
ServiceOrders::setStatus($hOs, 'pausada', $uid, 'Almoço'); ServiceOrders::setStatus($hOs, 'em_execucao', $uid); ServiceOrders::setStatus($hOs, 'concluida', $uid);
$h = OrderExec::hours($hOs);
ok(array_column($h['rows'], 'kind') === ['deslocamento', 'checkin', 'pausa_inicio', 'pausa_fim', 'checkout'] && $h['rows'][0]['label'] === 'A caminho da tarefa', 'atividades: a caminho, check-in, pausa e check-out');
ok($h['rows'][2]['reason'] === 'Almoço', 'motivo da pausa gravado ao pausar');
$hid = array_column($h['rows'], 'id', 'kind');
$d0 = date('Y-m-d', strtotime('-1 day'));
$set = fn($k, $at) => ['id' => $hid[$k], 'at' => "$d0 $at"];
ok(OrderExec::saveHours($hOs, ['rows' => [$set('deslocamento', '07:30'), $set('checkin', '08:00'), $set('pausa_inicio', '12:00'), $set('pausa_fim', '13:00'), $set('checkout', '17:30')]], $uid) === 5, 'ajuste de cinco horários (justificativa opcional)');
$h = OrderExec::hours($hOs);
ok($h['travel'] == 1800 && $h['work'] == 8.5 * 3600 && $h['pause'] == 3600, 'totais: 30 min de deslocamento, 8h30 de trabalho e 1h de pausa');
ok($h['rows'][3]['elapsed'] == 3600 && $h['rows'][1]['elapsed'] === null, 'tempo corrido aparece no fim da pausa');
$ci = $h['rows'][1];
ok($ci['at'] === "$d0 08:00:00" && $ci['original_at'] !== null && $ci['edited_by_name'] === 'Pedro', 'guarda horário original e quem alterou');
$hO = ServiceOrders::get($hOs);
ok($hO['started_at'] === "$d0 08:00:00" && $hO['finished_at'] === "$d0 17:30:00", 'início e fim do atendimento acompanham o check-in e o check-out');
$orig = $ci['original_at'];
OrderExec::saveHours($hOs, ['rows' => [['id' => $hid['checkin'], 'at' => "$d0 08:10", 'note' => 'Corrigido']]], $uid);
$ci = OrderExec::hours($hOs)['rows'][1];
ok($ci['original_at'] === $orig && $ci['note'] === 'Corrigido', 'segunda alteração mantém o horário original e grava a justificativa');
ok(OrderExec::saveHours($hOs, ['rows' => [['id' => $hid['checkin'], 'at' => "$d0 08:10", 'note' => 'Corrigido']]], $uid) === 0, 'nada mudou, nada conta');
ok(OrderExec::saveHours($hOs, ['rows' => [['id' => $hid['pausa_inicio'], 'reason' => 'Fim do expediente']]], $uid) === 1 && OrderExec::hours($hOs)['rows'][2]['reason'] === 'Fim do expediente', 'muda o motivo da pausa');
throws(fn() => OrderExec::saveHours($hOs, ['rows' => [['id' => $hid['pausa_inicio'], 'at' => "$d0 18:00"]]], $uid), 'seguir a ordem', 'pausa depois do check-out recusada');
throws(fn() => OrderExec::saveHours($hOs, ['rows' => [['id' => $hid['checkout'], 'at' => date('Y-m-d', strtotime('+2 days')) . ' 10:00']]], $uid), 'futuro', 'horário no futuro recusado');
throws(fn() => OrderExec::saveHours($hOs, ['rows' => [['id' => $hid['checkout'], 'at' => "$d0 25:00"]]], $uid), 'inválidos', 'horário inválido recusado');
$abertura = (int)DB::val("SELECT id FROM so_events WHERE order_id = ? AND to_status = 'aberta'", [$hOs]);
throws(fn() => OrderExec::saveHours($hOs, ['rows' => [['id' => $abertura, 'at' => "$d0 06:00"]]], $uid), 'não encontrada', 'abertura da OS não é atividade');
$outro = (int)DB::val('SELECT id FROM so_events WHERE order_id <> ? LIMIT 1', [$hOs]);
throws(fn() => OrderExec::saveHours($hOs, ['rows' => [['id' => $outro, 'at' => "$d0 10:00"]]], $uid), 'não encontrada', 'registro de outra OS não é alterado por esta');
// excluir
throws(fn() => OrderExec::saveHours($hOs, ['delete' => [['id' => $hid['checkin']]]], $uid), 'não pode vir depois', 'check-in com atividades depois não sai');
ok(OrderExec::saveHours($hOs, ['delete' => [['id' => $hid['deslocamento']]]], $uid) === 1, 'deslocamento excluído');
$h = OrderExec::hours($hOs);
ok($h['rows'][0]['kind'] === 'checkin' && $h['travel'] == 0 && $h['deleted'] === 1, 'sem deslocamento: totais refeitos e a exclusão fica no histórico');
ok(OrderExec::saveHours($hOs, ['delete' => [['id' => $hid['checkout']]]], $uid) === 1, 'check-out excluído');
$hO = ServiceOrders::get($hOs);
ok($hO['status'] === 'em_execucao' && $hO['finished_at'] === null && OrderExec::hours($hOs)['next'] === ['pausa_inicio', 'checkout'], 'sem check-out a OS volta para em execução');
// incluir atividades
throws(fn() => OrderExec::saveHours($hOs, ['add' => [['kind' => 'pausa_fim', 'at' => "$d0 15:00"]]], $uid), 'não pode vir depois', 'fim de pausa sem início recusado');
throws(fn() => OrderExec::saveHours($hOs, ['add' => [['kind' => 'checkin', 'at' => "$d0 15:00"]]], $uid), 'não pode vir depois', 'segundo check-in recusado');
throws(fn() => OrderExec::saveHours($hOs, ['add' => [['kind' => 'voar', 'at' => "$d0 15:00"]]], $uid), 'inválida', 'atividade desconhecida recusada');
ok(OrderExec::saveHours($hOs, ['add' => [['kind' => 'pausa_inicio', 'at' => "$d0 15:00", 'reason' => 'Aguardando peça', 'note' => 'Não registrada'], ['kind' => 'pausa_fim', 'at' => "$d0 15:30"]]], $uid) === 2, 'pausa incluída (início e fim)');
$h = OrderExec::hours($hOs);
ok(array_column($h['rows'], 'kind') === ['checkin', 'pausa_inicio', 'pausa_fim', 'pausa_inicio', 'pausa_fim'] && $h['rows'][3]['manual'] === 1 && $h['rows'][3]['reason'] === 'Aguardando peça', 'pausa incluída entra na ordem, marcada como manual');
throws(fn() => OrderExec::saveHours($hOs, ['add' => [['kind' => 'pausa_inicio', 'at' => "$d0 12:30"], ['kind' => 'pausa_fim', 'at' => "$d0 12:40"]]], $uid), 'não pode vir depois', 'pausa dentro de outra pausa recusada');
// check-out pela edição conclui a OS (e respeita as pendências)
$ttReq = TaskTypes::save(['name' => 'Exige relato (horas)', 'requires' => ['relato']]);
$hOs2 = ServiceOrders::save(['customer_id' => $cf, 'description' => 'Check-out pela edição', 'task_type_id' => $ttReq], $uid);
OrderExec::saveHours($hOs2, ['add' => [['kind' => 'deslocamento', 'at' => "$d0 07:00"], ['kind' => 'checkin', 'at' => "$d0 07:40"]]], $uid);
ok(ServiceOrders::get($hOs2)['status'] === 'em_execucao', 'incluir deslocamento e check-in deixa a OS em execução');
throws(fn() => OrderExec::saveHours($hOs2, ['add' => [['kind' => 'checkout', 'at' => "$d0 10:00"]]], $uid), 'Para registrar o check-out falta', 'check-out com pendência recusado');
OrderExec::saveReport($hOs2, ['report' => 'Feito']);
OrderExec::saveHours($hOs2, ['add' => [['kind' => 'checkout', 'at' => "$d0 10:00"]]], $uid);
$hO2 = ServiceOrders::get($hOs2);
ok($hO2['status'] === 'concluida' && $hO2['finished_at'] === "$d0 10:00:00" && OrderExec::hours($hOs2)['travel'] == 2400, 'check-out pela edição conclui a OS');
// pausa em andamento
$h3 = ServiceOrders::save(['customer_id' => $cf, 'description' => 'Pausada'], $uid);
ServiceOrders::setStatus($h3, 'agendada', $uid); ServiceOrders::setStatus($h3, 'em_execucao', $uid); ServiceOrders::setStatus($h3, 'pausada', $uid);
ok(OrderExec::hours($h3)['next'] === ['pausa_fim'] && OrderExec::hours($h3)['running'] === 'pause', 'em pausa, a próxima atividade é o fim da pausa');

echo "\nOrçamento e OS\n";
$oqCli = Customers::save(['type' => 'pf', 'name' => 'Cliente orçamento e OS']);
$oqOutro = Customers::save(['type' => 'pf', 'name' => 'Outro cliente']);
$oqSvc = Inventory::saveProduct(['type' => 'servico', 'name' => 'Revisão geral', 'unit' => 'un', 'sale_price' => '300']);
$oqQ = Quotes::save(['target' => 'cliente', 'customer_id' => $oqCli, 'items' => [['product_id' => $oqSvc, 'quantity' => 1]]], $uid);
$oqOs = ServiceOrders::save(['customer_id' => $oqCli, 'origin' => 'orcamento', 'quote_id' => $oqQ, 'description' => 'Atender o orçamento'], $uid);
$qq = Quotes::get($oqQ);
ok(count($qq['service_orders']) === 1 && (int)$qq['service_orders'][0]['id'] === $oqOs && $qq['service_orders'][0]['status_label'] === 'Aberta', 'orçamento mostra a OS gerada a partir dele');
$q2 = Quotes::save(['target' => 'cliente', 'customer_id' => $oqCli, 'service_order_id' => $oqOs, 'items' => [['product_id' => $oqSvc, 'quantity' => 2]]], $uid);
ok((int)Quotes::get($q2)['service_order_id'] === $oqOs && (int)Quotes::get($q2)['service_orders'][0]['id'] === $oqOs, 'orçamento aberto pela OS guarda e mostra a OS de origem');
$oqd = OrderExec::detail($oqOs)['quotes'];
ok(array_column($oqd, 'relation') === ['origem da OS', 'aberto pela OS'] && (int)$oqd[1]['id'] === $q2, 'OS mostra o orçamento de origem e o aberto por ela');
throws(fn() => Quotes::save(['target' => 'cliente', 'customer_id' => $oqOutro, 'service_order_id' => $oqOs, 'items' => [['product_id' => $oqSvc, 'quantity' => 1]]], $uid), 'outro cliente', 'orçamento para outro cliente não liga na OS');
throws(fn() => Quotes::save(['target' => 'lead', 'lead_name' => 'Lead', 'service_order_id' => $oqOs, 'items' => [['product_id' => $oqSvc, 'quantity' => 1]]], $uid), 'outro cliente', 'lead não liga na OS');
Quotes::save(['target' => 'cliente', 'customer_id' => $oqCli, 'service_order_id' => 999999, 'items' => [['product_id' => $oqSvc, 'quantity' => 3]]], $uid, $q2);
ok((int)Quotes::get($q2)['service_order_id'] === $oqOs, 'editar o orçamento não troca a OS de origem');

echo "\nExclusão de cadastros\n";
$rxNovo = Customers::save(['type' => 'pf', 'name' => 'Nunca usado']);
ok(Removals::delete('customers', $rxNovo) === 'apagado' && !DB::val('SELECT id FROM customers WHERE id = ?', [$rxNovo]), 'cliente nunca usado é apagado de vez');
$rxCli = Customers::save(['type' => 'pf', 'document' => '987.654.321-00', 'name' => 'Cliente X']);
$rxQ = Quotes::save(['target' => 'cliente', 'customer_id' => $rxCli, 'items' => [['product_id' => $oqSvc, 'quantity' => 1]]], $uid);
$rxEq = Equipments::save(['customer_id' => $rxCli, 'name' => 'Compressor X']);
$rxTt = TaskTypes::save(['name' => 'Tipo X']);
$rxQn = Questionnaires::save(['name' => 'Quest X', 'questions' => [['id' => 'p1', 'label' => 'Ok?', 'type' => 'texto']]]);
TaskTypes::save(['name' => 'Tipo X', 'questionnaire_ids' => [$rxQn]], $rxTt);
$rxOs = ServiceOrders::save(['customer_id' => $rxCli, 'equipment_id' => $rxEq, 'task_type_id' => $rxTt, 'description' => 'Atendimento X'], $uid);
OrderExec::saveAnswers($rxOs, $rxQn, ['p1' => 'sim'], null, $uid);
ok(Removals::delete('customers', $rxCli) === 'excluido', 'cliente já usado fica guardado como excluído');
$rxQq = Quotes::get($rxQ);
ok($rxQq['customer_name'] === 'Cliente X' && ServiceOrders::get($rxOs)['customer_name'] === 'Cliente X', 'orçamento e OS continuam mostrando o cliente excluído');
$rxList = array_column(DB::all('SELECT id FROM customers WHERE deleted_at IS NULL'), 'id');
ok(!in_array($rxCli, array_map('intval', $rxList), true), 'cliente excluído sai da lista');
Quotes::save(['target' => 'cliente', 'customer_id' => $rxCli, 'items' => [['product_id' => $oqSvc, 'quantity' => 2]]], $uid, $rxQ);
ok((int)Quotes::get($rxQ)['customer_id'] === $rxCli, 'editar o orçamento mantém o cliente excluído que já estava nele');
throws(fn() => Quotes::save(['target' => 'cliente', 'customer_id' => $rxCli, 'items' => [['product_id' => $oqSvc, 'quantity' => 1]]], $uid), 'Cliente excluído', 'orçamento novo não aceita cliente excluído');
throws(fn() => ServiceOrders::save(['customer_id' => $rxCli, 'description' => 'Nova'], $uid), 'Cliente excluído', 'OS nova não aceita cliente excluído');
throws(fn() => Customers::save(['type' => 'pf', 'name' => 'Mudou'], $rxCli), 'excluído não pode ser alterado', 'cliente excluído não é editado');
throws(fn() => Removals::delete('customers', Customers::ensureDefault()), 'não pode ser excluído', 'Consumidor final não se exclui');
ok(Removals::delete('equipments', $rxEq) === 'excluido' && ServiceOrders::get($rxOs)['equipment_id'] == $rxEq, 'equipamento usado fica guardado e a OS continua com ele');
ok(!in_array($rxEq, array_map(fn($e) => (int)$e['id'], Equipments::list(['all' => 1])), true), 'equipamento excluído sai da lista');
ok(Removals::delete('task_types', $rxTt) === 'excluido' && !in_array($rxTt, array_map(fn($t) => (int)$t['id'], TaskTypes::list(true)), true), 'tipo de tarefa usado fica guardado e sai da lista');
ok(Removals::delete('questionnaires', $rxQn) === 'excluido' && !in_array($rxQn, TaskTypes::get($rxTt)['questionnaire_ids'], true), 'questionário excluído sai dos tipos de tarefa');
ok(in_array($rxQn, array_map(fn($q) => (int)$q['id'], OrderExec::questionnaires(ServiceOrders::get($rxOs))), true), 'OS que respondeu continua mostrando o questionário excluído');
$rxBack = Customers::save(['type' => 'pf', 'document' => '98765432100', 'name' => 'Cliente X de volta']);
ok($rxBack === $rxCli && !Customers::get($rxCli)['deleted_at'], 'cadastrar de novo o mesmo CPF traz de volta o cliente excluído');
$rxSup = Customers::save(['type' => 'pj', 'name' => 'Fornecedor sem uso'], null, 'suppliers');
ok(Removals::delete('suppliers', $rxSup) === 'apagado', 'fornecedor nunca usado é apagado');

echo "\nQuestionário completo depois de editar\n";
$qcCli = Customers::save(['type' => 'pf', 'name' => 'Cliente questionário']);
$qcQn = Questionnaires::save(['name' => 'Checklist', 'questions' => [['id' => 'a', 'label' => 'Pressão', 'type' => 'texto', 'required' => true], ['id' => 'b', 'label' => 'Obs', 'type' => 'texto']]]);
$qcTt = TaskTypes::save(['name' => 'Com checklist', 'questionnaire_ids' => [$qcQn], 'requires' => ['questionario']]);
$qcOs = ServiceOrders::save(['customer_id' => $qcCli, 'task_type_id' => $qcTt, 'description' => 'Checklist'], $uid);
$qcGet = fn() => array_values(array_filter(OrderExec::questionnaires(ServiceOrders::get($qcOs)), fn($q) => (int)$q['id'] === $qcQn))[0];
OrderExec::saveAnswers($qcOs, $qcQn, ['a' => '8 bar', 'b' => 'ok'], null, $uid);
ok($qcGet()['answered'] && !$qcGet()['missing'] && $qcGet()['filled'] === 2 && $qcGet()['total'] === 2, 'questionário respondido e completo');
OrderExec::saveAnswers($qcOs, $qcQn, ['a' => '', 'b' => 'ok'], null, $uid);
ok($qcGet()['answered'] && $qcGet()['missing'] === ['Pressão'], 'apagar uma obrigatória deixa o questionário incompleto');
OrderExec::saveAnswers($qcOs, $qcQn, ['a' => '', 'b' => ''], null, $uid);
$qcP = OrderExec::pending(ServiceOrders::get($qcOs));
ok(!$qcGet()['answered'] && !DB::val('SELECT id FROM so_answers WHERE order_id = ?', [$qcOs]) && in_array('Responder o questionário "Checklist"', $qcP, true), 'apagar todas as respostas volta a "não respondido" e a pendência reaparece');

echo "\nPainel das ordens de serviço\n";
$pdCli = Customers::save(['type' => 'pf', 'name' => 'Cliente painel']);
$pdBefore = ServiceOrders::dashboard($uid);
$pdHoje = ServiceOrders::save(['customer_id' => $pdCli, 'description' => 'Hoje', 'technician_id' => $uid, 'scheduled_at' => date('Y-m-d') . ' 09:00'], $uid);
$pdAtr = ServiceOrders::save(['customer_id' => $pdCli, 'description' => 'Atrasada', 'scheduled_at' => date('Y-m-d', strtotime('-2 days')) . ' 10:00'], $uid);
$pdPrazo = ServiceOrders::save(['customer_id' => $pdCli, 'description' => 'Prazo vencido', 'due_date' => date('Y-m-d', strtotime('-1 day'))], $uid);
$pd = ServiceOrders::dashboard($uid);
ok($pd['open'] === $pdBefore['open'] + 3 && $pd['mine_today'] === $pdBefore['mine_today'] + 1 && in_array($pdHoje, array_map(fn($r) => (int)$r['id'], $pd['today']), true), 'painel conta as OS em aberto e a agenda de hoje (e as do técnico)');
$pdLate = array_map(fn($r) => (int)$r['id'], $pd['late']);
ok($pd['late_count'] === $pdBefore['late_count'] + 2 && in_array($pdAtr, $pdLate, true) && in_array($pdPrazo, $pdLate, true) && !in_array($pdHoje, $pdLate, true), 'painel mostra as atrasadas (agendada no passado ou prazo vencido)');
ok($pd['unscheduled'] === $pdBefore['unscheduled'] + 1, 'painel conta as OS sem agendamento');

@unlink($db);
echo "\n$passes passaram, $fails falharam\n";
exit($fails ? 1 : 0);
