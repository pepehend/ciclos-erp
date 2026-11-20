# CiclosERP

Sistema web de controle de estoque, vendas e lucro, feito para rodar em hospedagem compartilhada (cPanel) com PHP e MySQL. Funciona no computador e no celular, com login separado para cada sócio.

## O que ele faz

- **Produtos**: cadastro com unidade (un, kg, L…), categoria, código de barras, preço de venda, estoque mínimo, **observações** (aba Observações), **marca**, **código do fabricante**, **referências cruzadas** (códigos equivalentes de outros fabricantes, na aba Referências cruzadas) e **localização** no almoxarifado (ex.: Corredor 2 · Prateleira A3; aparece na lista e na página do produto, e a busca também procura por ela). A busca por código encontra o produto pelo SKU, pelo código do fabricante ou por qualquer referência cruzada, ignorando traços, pontos e espaços (22209-E1-K = 22209 E1 K = 22209e1k). Aba **Tributação** com os dados fiscais padrão do produto para NF-e (Origem, NCM, CEST, CFOP, unidade tributável, descrição na nota, cBenef, ICMS pelo CSOSN do Simples com crédito/ST/ST retido, PIS, COFINS, IPI e IBS/CBS), mostrando só os campos que a opção escolhida usa. Ao importar uma nota de compra, NCM, CEST e Origem são preenchidos sozinhos nos produtos que ainda não têm (origem 1/6 do fornecedor vira 2/7). Na edição dá para alterar o **valor de compra** (custo médio) à mão: a troca fica no histórico como "Ajuste de custo" e as próximas compras refazem a média a partir dele. No cadastro de um produto novo dá para informar o **estoque inicial** (entra no histórico como "Entrada — Estoque inicial", pelo custo inicial).
- **Importar produtos por planilha** (Produtos → Importar planilha): envie um .xlsx ou .csv com o cabeçalho na 1ª linha e as colunas A SKU · B código do fabricante · C nome · D marca · E preço de venda · F última movimentação · G localização · H estoque inicial · I observações. Na janela há o botão **Baixar planilha de exemplo** (`assets/product-import-example.xlsx`). O sistema mostra uma prévia (quantos entram, SKUs já cadastrados que serão pulados e linhas com erro) e cadastra em lotes, com barra de progresso. Estoque > 0 vira uma contagem (ajuste de inventário) no histórico na data da coluna F (ou hoje); estoque 0 fica só com um registro de entrada na data da importação. Linha sem nome usa o código do fabricante como nome. Na mesma janela, o administrador vê as **importações anteriores** (por dia) e pode **desfazer**: apaga de vez os produtos importados naquele dia que ainda não foram usados (os que já tiveram venda, orçamento, nota, ordem ou movimentação depois ficam).
- **Custo médio automático**: cada compra recalcula o custo médio ponderado do produto.
- **Clientes**: cadastro de empresas (CNPJ) e pessoas físicas (CPF). Digite o CNPJ e os dados (razão social, nome fantasia, endereço, telefone, e-mail, inscrição estadual e situação na Receita) são preenchidos automaticamente; o CEP também completa o endereço. Cada cliente tem histórico de compras, total comprado e produtos preferidos. Toda venda é feita para um cliente cadastrado; para vendas de balcão existe o cliente **Consumidor final**.
- **Orçamentos**: indicadores no topo (pendentes de envio, aguardando aprovação e, do mês atual, expiram este mês, recusados, aprovados e que viraram venda), visão em **lista** ou **kanban** (arraste os cartões para mudar o status) e exportação CSV. O botão **Filtros** abre período de criação, cliente (nome, fantasia, lead ou CNPJ/CPF), status, valor mínimo e máximo e responsável (o mesmo painel fica na tela de **Vendas**, com o vendedor). Marque orçamentos pela caixinha (na lista ou no kanban) e use **Ações em massa**: Alterar status, Download PDF (um PDF por orçamento num .zip), Marcar como enviado/aprovado/recusado e Excluir; as alterações pedem confirmação digitando a palavra ("alterar" ou "excluir"), e os orçamentos que não podem receber a ação (ex.: lead não aprova) aparecem numa lista com o motivo. Cada orçamento tem cliente ou *lead*, datas de solicitação e validade, responsável, código externo, **produtos, serviços e custos adicionais** (com desconto por item e desconto geral em R$ ou %), forma e condição de pagamento, observação (vai para o cliente) e nota interna (só a equipe). Todo orçamento precisa de um cliente ou de um *lead* (com nome) para ser salvo. No topo do orçamento fica a barra de resumo com o **valor orçado**, o **status** (dá para trocar ali mesmo) e a **data de cadastro**. Depois de salvo, o orçamento abre só para visualizar (tudo como texto, com a lateral de dados do cliente e o quadro “Status de aprovação”, sem botões de incluir ou remover itens): os botões são **Visualizar orçamento** (o link que o cliente vê, onde ele pode baixar o PDF, imprimir e **aprovar ou recusar**), **Editar** (libera os campos) e **Mais ações**: Copiar link, Download PDF (baixa o arquivo .pdf), Enviar por E-mail, Enviar por WhatsApp, Marcar como enviado, Marcar como aprovado, Marcar como recusado, Duplicar (abre um orçamento novo igual, com o cliente em branco) e Excluir. Em **Configurações → Orçamento** você cria status próprios (ex.: "Em negociação"), renomeia os existentes, escolhe a cor de cada um e a ordem (que é a ordem das colunas do kanban); os status do sistema não podem ser excluídos, e os criados por você podem ser desativados ou excluídos se nenhum orçamento estiver neles. Em **Configurações → Orçamento** ficam a validade padrão (dias) e a observação padrão de um orçamento novo, e lá você escolhe para qual status o orçamento vai ao marcar como enviado, aprovado ou recusado. Só orçamento de **cliente cadastrado** pode ser aprovado e virar venda: o lead precisa ser cadastrado como cliente antes (no link, o lead vê o contato do responsável no lugar do botão Aprovar). Orçamento aprovado vira **venda com um clique**, com baixa no estoque. Os dados da empresa do cabeçalho (com **logo** e endereço buscado pelo **CEP**) ficam em **Configurações → Dados da empresa** (administrador).
- **Serviços**: cadastro próprio e simples (nome e preço), separado dos produtos. Não controlam estoque.
- **Excluir produtos e serviços** (administrador): botão Excluir na página do produto e na lista/cadastro de serviços. Item nunca usado é apagado de vez; item que já aparece em orçamentos, vendas, notas ou movimentações sai do cadastro e de todas as listas, mas continua nos orçamentos e vendas antigos como era (marcado como “excluído do cadastro”). Produto com estoque precisa ser zerado antes.
- **Fornecedores**: cadastro pelo CNPJ (dados da Receita), com total comprado, notas e produtos fornecidos. Ao importar uma nota, o fornecedor é cadastrado/ligado automaticamente.
- **Ordens de compra**: pedido formal de peças e produtos ao fornecedor, com os produtos cadastrados, quantidade, custo combinado, frete, desconto, previsão de entrega, forma e condição de pagamento e observações. O botão **Abaixo do mínimo** sugere os itens a repor, já descontando o que está em outras ordens abertas. **Salvar e enviar** gera um link (`pedido.php`) para o fornecedor ver, imprimir/salvar em PDF e **confirmar o pedido**; o envio é por WhatsApp (com a lista de itens na mensagem), e-mail ou cópia do link. Status: rascunho → enviada → confirmada → recebida (ou cancelada). A ordem **não mexe no estoque nem no financeiro**: a entrada no estoque continua sendo feita ao importar a nota fiscal da compra.
- **Financeiro → Pagamentos e recebimentos**: contas a pagar e a receber com categoria, cliente ou fornecedor, forma de pagamento e **parcelas** (dividir o valor ou repetir todo mês). Baixa com data e valor; se pagar só parte, o restante fica em aberto. Contas vencidas aparecem em destaque. **As vendas entram sozinhas como recebidas** e, se a venda for cancelada, o recebimento é cancelado junto.
- **Financeiro → Fluxo de caixa**: entradas e saídas realizadas e previstas (contas em aberto), por dia ou por mês, com gráfico, saldo dia a dia, saldo previsto, totais por categoria e exportação CSV. O **saldo inicial do caixa** (quanto havia antes do primeiro lançamento) é ajustado na própria tela.
- **Permissões por perfil**: em Configurações → Sistema → Permissões você cria perfis com o nome que quiser (ex.: Vendedor, Estoquista, Caixa), marca as áreas liberadas em cada um (vendas, orçamentos, notas, produtos, serviços, clientes, fornecedores, relatórios, movimentações, ordens de compra e financeiro) e escolhe o perfil de cada usuário. Alterar um perfil muda todos os usuários dele. O bloqueio vale também no servidor. Quem não tem "Relatórios" não vê faturamento e lucro no painel. Administradores sempre têm acesso total.
- **Marca da empresa no menu**: o topo da barra lateral (centralizado), o nome da aba do navegador e o ícone da aba mostram o logo e o nome fantasia cadastrados em Configurações → Dados da empresa (que guarda também a razão social, buscada pelo CNPJ e mostrada junto do CNPJ no orçamento e no pedido de compra) (sem eles, o ícone e "CiclosERP").
- **Tema claro ou escuro**: em Minha conta → Aparência (Claro, Escuro ou Automático, que segue o aparelho), ou pelo botão de tema no rodapé do menu: cada clique passa de claro (sol) para escuro (lua) e para automático (o ícone do tema do aparelho com um "A"). A escolha fica salva em cada aparelho.
- **Cor de destaque**: em Minha conta → Aparência cada usuário escolhe a cor dos botões, do menu e dos links (cores prontas ou qualquer outra em #RRGGBB). É aplicada e salva na hora, vale em qualquer aparelho em que a pessoa entrar e se ajusta aos temas claro e escuro. PDFs e páginas enviadas a clientes e fornecedores continuam na cor padrão.
- **Menu**: Painel, botão **Novo** (abre as opções Novo orçamento, Nova venda e Nova ordem de compra; o mesmo botão fica no painel), Importar nota e grupos recolhíveis Operação, Cadastros (Produtos, Serviços, Clientes, Fornecedores, Formas de pagamento, Condições de pagamento), Financeiro, Relatórios e **Configurações** (Dados da empresa, Orçamento, Vendas, Notas fiscais, Ordens de compra e o submenu recolhível Sistema → Usuários, Permissões e Backup). Configurações só aparece para administradores; a Aparência de cada um fica em Minha conta.
- **Formas de pagamento** (Cadastros): já vêm Dinheiro, PIX, Cartão de débito, Cartão de crédito, Boleto, Transferência, Cheque e Outro. Dá para cadastrar novas, renomear, mudar a ordem, desativar (some das listas, o histórico continua) e excluir as que nunca foram usadas. Valem para vendas, orçamentos, financeiro e ordens de compra.
- **Condições de pagamento** (Cadastros): lista de condições ("30/60/90 dias", "Na entrega"…) sugeridas no orçamento, na venda e na ordem de compra. Dá para cadastrar, editar, ordenar, desativar e excluir; e também cadastrar uma nova direto no campo, digitando o texto e escolhendo "Cadastrar". A condição pode definir parcelas, intervalo e se tem entrada (o valor da entrada é digitado no orçamento ou na venda): ao escolhê-la, o parcelamento é preenchido (se a forma de pagamento permitir). A venda guarda a condição.
- **Configurações de Vendas, Notas fiscais e Ordens de compra** (uma tela para cada, direto no menu Configurações), só administradores. A de Orçamento junta comportamento e status numa tela só:
  - **Vendas:** cliente, forma de pagamento e observação padrão da nova venda. Também define se dá para vender sem estoque (o saldo fica negativo), se a venda à vista entra como recebida ou em aberto, o desconto máximo e quem pode cancelar (e até quantos dias depois). As duas últimas regras valem para quem não é administrador.
  - **Notas fiscais:** CNPJs aceitos (ou qualquer CNPJ), se "Lançar em Contas a pagar" vem marcado e a forma de pagamento padrão das contas. Também define se o NCM, o CEST e a origem da nota preenchem só o que falta, sempre ou nunca, e o preço de venda pelo custo + margem (não mexer, sugerir ou já vir marcado para atualizar, ajustável na conferência).
  - **Ordens de compra:** prazo de entrega, forma, condição e observação padrão da ordem nova, além de nome e cor dos status. Também define se o fornecedor pode confirmar pelo link e a mensagem de envio por WhatsApp e e-mail, com {fornecedor}, {numero}, {entrega}, {itens}, {link} e {empresa}.
- **Parcelamento**: cada forma de pagamento tem o máximo de pagamentos e o intervalo (sempre de 30 em 30 dias, ou livre). Já vêm Cartão de crédito até 12x (30 em 30) e Boleto até 12x (livre). O parcelamento do orçamento e da venda vem da **condição de pagamento** escolhida (ex.: "30/60/90 dias", "Entrada + 28/56/84 dias"): aparece a tabela Parcela / Valor / Vencimento; com entrada, aparece o campo **Valor da entrada**, em R$ ou em % do total (em branco = igual às parcelas; em %, acompanha o total se ele mudar) e o restante é dividido nas parcelas, com a linha da entrada vencendo no dia da venda; os valores podem ser ajustados e, com intervalo livre, os vencimentos também. Venda parcelada vira uma conta a receber por parcela (e a entrada); venda à vista continua entrando como recebida. O orçamento enviado ao cliente mostra a tabela.
- **Máscaras**: telefone, CPF e CNPJ são formatados enquanto você digita (ex.: (31) 99876-5432, 123.456.789-01, 12.345.678/0001-90), em todos os campos desses tipos.
- **Responsável no orçamento**: cada usuário tem telefone (em Minha conta ou em Usuários); o cabeçalho do orçamento enviado ao cliente mostra o nome, o telefone e o e-mail do responsável.
- **Vendas**: tela de venda rápida (aceita leitor de código de barras), com desconto, forma de pagamento e cliente. A venda baixa o estoque e registra o custo do momento, para o lucro ficar certo. Uma venda cancelada devolve os itens ao estoque.
- **Só notas da empresa**: a importação (XML, DANFE ou cupom) só aceita notas emitidas para o CNPJ cadastrado em Configurações → Dados da empresa ou para os **CNPJs aceitos** (filiais, outras empresas do grupo; CNPJ e nome, o nome é buscado pelo CNPJ) cadastrados em Configurações → Notas fiscais. Lá também dá para aceitar notas de **qualquer CNPJ**. Nota para outro CNPJ, sem destinatário ou cupom sem o CNPJ do comprador é bloqueada, com o motivo na tela. O lançamento manual ("Digitar itens", compra sem nota) continua liberado.
- **Importação de notas fiscais de compra**, com entrada automática no estoque e **contas a pagar automáticas**: se a nota traz o faturamento (duplicatas/boletos com vencimento e valor), as parcelas aparecem na conferência e entram em Financeiro → Contas a pagar, ligadas ao fornecedor e à nota. Dá para ajustar, marcar parcela já paga ou lançar parcelas à mão. Desfazer a nota remove as parcelas em aberto.
  - **XML da NF-e ou da NFC-e**: leitura exata. O custo de cada item já inclui frete, IPI, ICMS-ST e descontos.
  - **PDF do DANFE**: leitura aproximada, então confira os itens antes de confirmar.
  - **Cupom fiscal (NFC-e) pelo QR Code**: escaneie com a câmera do celular ou cole o link. O sistema consulta a SEFAZ.
  - **Digitar itens**: para nota em papel ou quando a leitura falha.
  - O sistema **lembra** qual produto do seu estoque corresponde a cada item de cada fornecedor e a conversão de unidades (ex.: 1 saco = 25 kg). Da segunda nota em diante, o trabalho é só conferir.
  - Ele **bloqueia nota duplicada** pela chave de acesso.
  - Uma nota importada por engano pode ser desfeita (administrador).
- **Movimentações**: entrada sem nota, saída/uso, perda e contagem de inventário, com histórico completo de quem fez o quê.
- **Relatórios**: faturamento, custo, lucro bruto, margem, ticket médio, perdas, compras, valor em estoque, vendas por produto, por dia e por forma de pagamento. Exporta CSV (abre no Excel) e imprime.
- **Usuários**: administradores e membros. Só o administrador gerencia usuários, desativa produtos, desfaz notas e baixa o backup. Em Configurações → Sistema → Usuários → Editar, o administrador troca nome, **e-mail** (login), telefone, perfil e senha e pode **Excluir** um membro: quem nunca usou o sistema é apagado de vez; quem já tem vendas, orçamentos, notas ou outros registros só é desativado, para manter o histórico.
- **Portal do cliente** (`<endereço do sistema>/cliente/`, por exemplo `https://seudominio.com.br/sistema/cliente/`): o cliente entra com o **CPF ou CNPJ** e uma senha e vê os **orçamentos** (com aprovar e recusar), as **compras** (com itens e parcelas), as **parcelas** em aberto, pagas e vencidas, e os **dados cadastrais**. Ali ele também pode pedir correção dos dados e trocar a senha. O botão de sol/lua no topo alterna entre o **tema claro e o escuro** (na primeira visita, segue o do aparelho; a escolha fica salva no navegador).
  - **Liberar o acesso:** na tela do cliente (Cadastros → Clientes → abrir o cliente), cartão **Portal do cliente** → **Gerar link de acesso**. Envie a mensagem pelo WhatsApp ou copie. O link vale 7 dias e só funciona uma vez: o cliente cria a senha e já entra. O cliente precisa ter CPF ou CNPJ cadastrado.
  - **Esqueceu a senha:** gere um link novo no mesmo cartão. **Remover acesso** apaga a senha e o link.
  - **Pedidos de correção:** aparecem no cartão, com **Marcar como resolvido**, e na lista de clientes com a etiqueta "correção pedida".
  - **O que o cliente não vê:** orçamentos em rascunho, em elaboração ou aguardando envio, e nada de outros clientes. Cliente desativado perde o acesso na hora.
- **Administradores protegidos**: pela tela, um administrador altera só os próprios dados e os dos membros; os dados de **outro administrador** (nome, e-mail, telefone, senha) não mudam, e ninguém rebaixa, desativa ou exclui um administrador. Isso só é feito pelo console do servidor; no cPanel, em **Terminal** (se a hospedagem liberar):
  ```
  php ~/public_html/sistema/app/users-cli.php listar
  php ~/public_html/sistema/app/users-cli.php desativar fulano@empresa.com
  ```
  Comandos: `listar`, `desativar`, `ativar`, `rebaixar` (administrador vira membro), `promover`, `excluir`, `senha` (gera uma senha nova e mostra na tela) e `email <e-mail> <novo-e-mail>`. O sistema nunca fica sem nenhum administrador ativo. Sem Terminal, dá para fazer pelo phpMyAdmin, tabela `users` (`active` = 0 desativa; `role` = `membro` rebaixa).
- **Backup** em um clique (arquivo .sql) e **backup automático no Google Drive** (Configurações → Sistema → Backup).

## Requisitos da hospedagem

- PHP 8.0 ou mais novo (recomendado 8.2+), com as extensões `pdo_mysql`, `mbstring`, `dom`, `zlib`, `iconv` e `curl`. Quase toda hospedagem já vem com elas.
- MySQL 5.7+ ou MariaDB 10.3+
- **HTTPS (SSL) ativo** no domínio. É necessário para usar a câmera na leitura do QR Code e para proteger as senhas. No cPanel, normalmente é só ativar o "AutoSSL" ou o "Let's Encrypt".

## Instalação no cPanel (passo a passo)

1. **Crie o banco de dados.** No cPanel, abra **Bancos de dados MySQL** (ou "Assistente de banco de dados MySQL"):
   - crie um banco (ex.: `estoque`; o cPanel vai chamá-lo de algo como `seuusuario_estoque`);
   - crie um usuário com uma senha forte;
   - adicione o usuário ao banco marcando **TODOS OS PRIVILÉGIOS**.
   - Anote o nome do banco, o usuário e a senha.
2. **Baixe o sistema pelo Git do cPanel** (o repositório é público, não precisa de senha nem chave):
   - cPanel → **Git™ Version Control** → **Criar**;
   - URL do clone: `https://github.com/pepehend/ciclos-erp.git`;
   - Caminho do repositório: `repositories/ciclos-erp`; nome: `ciclos-erp`; clique em **Criar**;
   - em **Gerenciar → Extrair ou implantar**, clique em **Implantar commit HEAD**. Os arquivos vão para `public_html/sistema` (para outra pasta, mude o `DEPLOYPATH` no `.cpanel.yml`). Na primeira vez o `install.php` vai junto.
   - Sem Git no cPanel: baixe o `.zip` em github.com/pepehend/ciclos-erp (**Code → Download ZIP**), envie para `public_html` pelo Gerenciador de arquivos e extraia.
3. **Instale.** No navegador, abra `https://seudominio.com.br/sistema/install.php` (ajuste o endereço para o seu) e preencha:
   - Servidor: normalmente `localhost`
   - nome do banco, usuário e senha anotados no passo 1
   - seu nome, e-mail e senha de administrador
4. Clique em **Instalar**. Depois, **apague o arquivo `install.php`** pelo Gerenciador de arquivos. Ele já se bloqueia sozinho, mas é mais seguro removê-lo.
5. Entre no sistema e cadastre seus sócios em **Usuários**.

> Se aparecer o aviso "não consegui gravar app/config.php", dê permissão de escrita à pasta `app` (botão direito → Permissões → 755) e tente de novo, ou crie o arquivo manualmente com o conteúdo que o instalador mostrar.

## Primeiros passos sugeridos

1. Cadastre os **produtos** que você vende (ou deixe que a importação de notas cadastre).
2. Importe as **notas de compra** que você tiver (de preferência em XML), ou lance entradas manuais com o valor pago.
3. Venda pela tela **Nova venda** e acompanhe em **Relatórios**.

## Sobre a leitura de notas

| Formato | Precisão | Observação |
|---|---|---|
| XML (NF-e / NFC-e) | Exata | Peça o XML ao fornecedor. A maioria envia por e-mail junto com o PDF. |
| PDF do DANFE | Boa, mas confira | Funciona com DANFEs gerados por sistema (com texto). PDF escaneado (imagem) não é lido. |
| QR Code do cupom | Depende do estado | Usa o portal da SEFAZ de cada estado. Alguns estados pedem captcha ou mudam o layout; nesses casos, use o XML ou digite os itens. |

O custo que entra no estoque é o **valor efetivamente pago**: inclui frete, IPI, ICMS-ST e outras despesas e desconta os descontos. No PDF e no cupom, essa diferença é distribuída proporcionalmente entre os itens.

## Instalação numa VM Debian 13 (alternativa ao cPanel)

O mesmo sistema roda num servidor próprio (VM) com Debian 13. Num servidor limpo, como **root**:

```bash
apt-get update && apt-get install -y git
git clone https://github.com/pepehend/ciclos-erp.git /opt/ciclos-erp
bash /opt/ciclos-erp/deploy/debian/install.sh --domain sistema.suaempresa.com.br --ssl seu@email.com
```

Não precisa do link "raw" do GitHub: o repositório é público e o script usa o próprio clone. Sem `--domain`, ele pergunta o domínio (Enter usa o IP da VM).

- O script instala Apache, PHP e MariaDB, publica o sistema em `/var/www/ciclos-erp`, cria o banco de dados (usuário e senha aparecem no fim e ficam em `/root/ciclos-erp-banco.txt`) e ativa o HTTPS (Let's Encrypt) quando você passa `--ssl` e o domínio já aponta para a VM. Sem domínio ainda, use o IP da VM em `--domain` e rode sem `--ssl`.
- Depois abra `https://<domínio>/install.php`, escolha **MySQL**, use os dados do banco e, no fim, apague o instalador: `rm /var/www/ciclos-erp/install.php`.
- Atualizações: o cron do servidor (`/etc/cron.d/ciclos-erp`) roda o mesmo `deploy/auto-deploy.sh` a cada 5 minutos e publica a `main` aprovada, igual ao cPanel. O backup automático no Google Drive também funciona.
- E-mails (OS digital, pesquisa, convite do portal) saem pela função `mail()` do PHP: instale e configure um envio de e-mail no servidor (ex.: `apt install msmtp-mta`).

## Atualizações (GitHub + cPanel)

O código fica no repositório público `pepehend/ciclos-erp`. Toda alteração chega como *pull request*; ao aprovar (**Merge**), ela vai para a branch `main`.

**Publicação automática** (configurar uma vez): no cPanel → **Cron Jobs** → adicione:

- Configuração comum: **A cada 15 minutos** (`*/15 * * * *`). A hospedagem não aceita intervalos menores: se você puser menos, ela troca sozinha por 15 minutos ou um pouco mais (`*/16`, `*/17`), o que é normal.
- Comando: `/bin/bash $HOME/repositories/ciclos-erp/deploy/auto-deploy.sh >/dev/null 2>&1` (troque `ciclos-erp` pelo nome da pasta do repositório no cPanel; na instalação antiga é `sistema-gestao`)

A cada 15 minutos (mais ou menos) o script confere o GitHub; se houver versão nova aprovada, atualiza o repositório e publica em `public_html/sistema` (usando o `.cpanel.yml`). O `config.php`, os dados e o `install.php` nunca são sobrescritos. O registro fica em `logs/ciclos-erp-deploy.log` na sua pasta pessoal (só quando publica ou dá erro).

Publicar na hora, sem esperar: cPanel → Git Version Control → Gerenciar → Extrair ou implantar → **Atualizar do remoto** e **Implantar commit HEAD**.

## Backup

- Em **Configurações → Sistema → Backup → Baixar backup** você baixa uma cópia completa dos dados (.sql). Faça isso com frequência e guarde fora da hospedagem.
- **Backup automático no Google Drive** (Configurações → Sistema → Backup), sem Google Cloud: a tela mostra um pequeno script pronto (com uma chave secreta deste sistema). Cole em script.google.com → Novo projeto, publique em **Implantar → Nova implantação → App da Web** (executar como você, acesso "Qualquer pessoa") e cole no sistema o **URL do app da Web** e o **link da pasta** do Drive onde os backups vão ficar. O sistema testa a ligação e mostra a pasta e a conta. Depois escolha frequência (todo dia ou toda semana), horário e quantos backups guardar: o banco vai compactado (.sql.gz) e os backups mais antigos vão para a lixeira (os outros arquivos da pasta não são tocados). Sem a chave, o script recusa qualquer envio. Quem dispara é a mesma tarefa de **publicação automática** do Cron Jobs (a cada 15 minutos, mais ou menos; não precisa de outro Cron Job); os envios e erros ficam em `logs/estoque-deploy.log`. O botão **Fazer backup agora** envia na hora. A chave e o endereço do script não vão dentro do arquivo de backup.
- A maioria das hospedagens também faz backup automático do MySQL (veja "Backup" no cPanel).
- Para restaurar: rode o instalador num banco vazio e depois importe o arquivo .sql pelo **phpMyAdmin**.

## Segurança

- As senhas são guardadas criptografadas (bcrypt). O login é bloqueado temporariamente após várias tentativas erradas.
- Todas as ações são protegidas contra CSRF, e as pastas internas (`app`, `data`) são bloqueadas para acesso direto via `.htaccess`.
- Use sempre HTTPS e senhas fortes, e apague o `install.php` depois da instalação.

## Estrutura dos arquivos

```
index.php          Página do sistema (carrega a interface)
orcamento.php      Página pública do orçamento (link enviado ao cliente)
pedido.php         Página pública da ordem de compra (link enviado ao fornecedor)
api.php            API usada pelas telas (todas as rotas)
install.php        Instalador (apague depois de instalar)
assets/            Interface: app.js, style.css e o leitor de QR Code (jsQR)
app/bootstrap.php  Inicialização
app/config.php     Gerado pelo instalador (dados do banco)
app/lib/           Regras do sistema:
  db.php           conexão e criação das tabelas
  inventory.php    estoque, custo médio, vendas
  invoices.php     leitura de XML, DANFE, NFC-e e entrada das notas
  reports.php      relatórios
  customers.php    clientes, consulta de CNPJ e CEP
  quotes.php       orçamentos (status, link público, conversão em venda)
  purchases.php    ordens de compra (pedido ao fornecedor, link público)
  finance.php      contas a pagar e a receber, fluxo de caixa
  settings.php     dados da empresa
  auth.php         login, sessões, permissões
  backup.php       exportação .sql
app/vendor/        Biblioteca de leitura de PDF (smalot/pdfparser, LGPL-3.0)
test/              Testes automatizados (não precisa enviar para a hospedagem)
```

## Testes

Para quem for alterar o código: `php test/run.php` roda mais de 80 testes da lógica (custo médio, vendas, cancelamentos, leitura de notas, relatórios) num banco SQLite temporário.

## Licenças de terceiros

- [smalot/pdfparser](https://github.com/smalot/pdfparser): LGPL-3.0
- [jsQR](https://github.com/cozmo/jsQR): Apache-2.0
