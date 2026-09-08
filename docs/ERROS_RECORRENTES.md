# Erros recorrentes e prevenção

Esta base contém somente falhas com causa confirmada, solução reproduzível e prevenção útil para o GOVNEX GAB ou sua futura operação em VPS. Não registre hipóteses, erros de digitação ou indisponibilidades transitórias.

## Formato de registro

Cada caso deve informar contexto, sintoma, causa confirmada, correção, validação e prevenção.

## ERR-COMPOSER-001 - Runtime abaixo do lockfile

- **Contexto:** instalação ou CI com PHP 8.3 enquanto o projeto usa OpenSpout 5.8 e dependências Symfony 8.
- **Sintoma:** `composer install` rejeita o ambiente ou exige reduzir dependências; a tentativa de contorno remove recursos como imagem em XLSX.
- **Causa confirmada:** o lockfile atual exige PHP 8.4.1 ou superior, mas manifesto, CI ou imagem estavam em versão anterior.
- **Correção:** alinhar `composer.json`, plataforma Composer, CI e imagem Docker a PHP 8.4.1 ou superior; manter OpenSpout 5.8 e reinstalar pelo lockfile.
- **Validação:** executar `composer validate`, `composer ci:check` e o teste de exportação que confere `xl/media/image1.png` no XLSX.
- **Prevenção:** tratar o lockfile como fonte da compatibilidade e atualizar todas as definições de runtime na mesma mudança.

## ERR-TEST-001 - Suíte herda o ambiente do container web

- **Contexto:** `composer ci:check` executado dentro do container de desenvolvimento que já possui `APP_ENV=local` e conexão MariaDB.
- **Sintoma:** dezenas de testes HTTP falham simultaneamente com `419`, respostas não Inertia ou dados persistidos fora do banco SQLite esperado.
- **Causa confirmada:** as variáveis do processo tinham precedência e o autoload era executado antes de o PHPUnit aplicar os valores do `phpunit.xml`.
- **Correção:** usar `tests/bootstrap.php` para definir o ambiente seguro antes do autoload e manter `force="true"` nos valores equivalentes do `phpunit.xml`.
- **Validação:** executar `composer ci:check` no mesmo container local e confirmar que a suíte usa `APP_ENV=testing` sem acessar o MariaDB de desenvolvimento.
- **Prevenção:** manter os valores de teste isolados com `force="true"` e nunca depender do ambiente herdado pelo processo do container.

## ERR-PACKAGE-001 - Link local de storage interrompe o pacote Windows

- **Contexto:** criação da release pelo `tar` do Windows em um checkout com `public/storage` apontando para o storage local.
- **Sintoma:** o empacotador falha com `Cannot stat: Invalid argument` ao alcançar `public/storage`.
- **Causa confirmada:** o link local não é um artefato portável da release e o `tar` tentou resolvê-lo no host Windows.
- **Correção:** gerar o pacote com `tar` dentro do container Linux, excluir `public/storage` e recriar o vínculo no container ou volume persistente de produção.
- **Validação:** gerar o arquivo, conferir o checksum e confirmar que `public/storage`, `storage`, uploads e credenciais não estão no inventário.
- **Prevenção:** tratar links e dados de runtime como estado externo à release imutável.

## ERR-CI-001 - Workflow tenta migrar o banco local do arquivo de exemplo

- **Contexto:** pipeline do GitHub Actions executada em runner novo, sem serviço MariaDB.
- **Sintoma:** a etapa de preparação falha com `SQLSTATE[HY000] [2002] Connection refused` ao executar migrations no host `127.0.0.1`.
- **Causa confirmada:** `composer setup` copia o `.env.example` e executa `php artisan migrate --force`; esse comando prepara uma estação local e não é apropriado para CI isolada.
- **Correção:** instalar Composer e npm diretamente, executar a suíte com o bootstrap SQLite de testes e realizar o build em etapa separada.
- **Validação:** confirmar no pull request que auditoria npm, `composer ci:check` e `npm run build` concluem no runner sem banco externo.
- **Prevenção:** reservar `composer setup` ao ambiente local e manter o workflow independente de serviços que não estejam declarados no próprio job.

## ERR-CI-002 - ESLint roda antes dos arquivos gerados pelo Wayfinder

- **Contexto:** pipeline executada em checkout limpo, sem os arquivos ignorados de rotas e ações geradas.
- **Sintoma:** o ESLint apresenta erros `import/order` que não aparecem em uma estação onde o Vite já foi executado.
- **Causa confirmada:** `composer ci:check` era executado antes de `npm run build`; o build é responsável por gerar os módulos usados para resolver corretamente os grupos de imports.
- **Correção:** gerar os assets de produção antes dos checks de lint, tipos e testes.
- **Validação:** confirmar que um runner novo conclui `npm run build` e depois `composer ci:check` sem alterações no worktree rastreado.
- **Prevenção:** preservar a ordem `instalação → auditoria → build → checks` no workflow.

## ERR-SSH-001 - Variável remota expandida pelo PowerShell

- **Contexto:** comando SSH iniciado no PowerShell com `$variavel` em string delimitada por aspas duplas.
- **Sintoma:** a variável chega vazia ou alterada ao shell remoto e o comando atua em caminho incorreto.
- **Causa confirmada:** o PowerShell expande a variável localmente antes de enviar o texto ao SSH.
- **Correção:** interromper a operação, conferir artefatos criados e reexecutar por script shell validado e transferido via `scp`.
- **Validação:** conferir os caminhos absolutos, argumentos e estado do serviço antes de prosseguir.
- **Prevenção:** não montar scripts remotos complexos em strings PowerShell.

## ERR-SHELL-001 - Quoting aninhado altera argumentos

- **Contexto:** combinação de PowerShell, SSH, Bash, SQL ou `php -r` no mesmo comando.
- **Sintoma:** aspas desaparecem, argumentos são divididos ou variáveis mudam de camada.
- **Causa confirmada:** cada interpretador processa escapes e aspas em ordem diferente.
- **Correção:** mover a lógica para um arquivo temporário na linguagem final, validar sua sintaxe e executá-lo diretamente.
- **Validação:** começar por modo de leitura ou simulação e conferir argumentos e destinos antes da escrita.
- **Prevenção:** preferir scripts versionáveis ou temporários transferidos por `scp`; evitar comandos inline extensos.

## ERR-TRAEFIK-001 - Backend preso a IP Docker

- **Contexto:** router ou service do Traefik apontando para endereço `172.x.x.x` de um container.
- **Sintoma:** o domínio retorna `502 Bad Gateway` embora a aplicação esteja saudável.
- **Causa confirmada:** ao recriar containers, o IP muda ou passa a pertencer a outro serviço.
- **Correção:** usar descoberta por labels ou nome do serviço na mesma rede Docker e remover a rota antiga somente após validar o novo backend.
- **Validação:** conferir provider, backend `UP`, HTTPS público, certificado e serviços vizinhos.
- **Prevenção:** declarar rede e porta interna nas labels; nunca usar IP efêmero de container.

## ERR-LARAVEL-PROXY-001 - URLs HTTP atrás de proxy HTTPS

- **Contexto:** Laravel publicado por proxy reverso HTTPS sem configuração adequada de proxies confiáveis.
- **Sintoma:** HTML abre em HTTPS, mas assets ou formulários usam `http://`, são bloqueados e o login parece não responder.
- **Causa confirmada:** a aplicação interpreta a conexão interna do proxy como HTTP e ignora `X-Forwarded-Proto`.
- **Correção:** configurar proxies e cabeçalhos encaminhados de forma restrita, mantendo o serviço web sem porta administrativa pública.
- **Validação:** conferir URLs HTTPS no HTML e executar carregamento de assets e POST com sessão e CSRF.
- **Prevenção:** incluir um teste com `X-Forwarded-Proto: https` e smoke test público em toda publicação atrás de proxy.

## ERR-LARAVEL-BOOT-001 - Configuração acessada cedo demais no bootstrap

- **Contexto:** configuração de proxies confiáveis em `bootstrap/app.php` durante `composer install` ou descoberta de pacotes.
- **Sintoma:** comandos Composer ou Artisan falham antes de o container de serviços do Laravel estar disponível.
- **Causa confirmada:** `config()` foi chamado dentro de `withMiddleware`, em uma fase inicial na qual o repositório de configuração ainda não estava registrado.
- **Correção:** ler a variável de processo em `$_SERVER`, `$_ENV` ou `getenv()` nessa etapa e validar o valor antes de configurar os proxies.
- **Validação:** executar `composer install`, `composer ci:check`, `php artisan package:discover` e o smoke test HTTPS atrás do Traefik.
- **Prevenção:** não usar helpers dependentes do container em callbacks de bootstrap executados durante a montagem da aplicação.

## ERR-DOCKER-NET-001 - Rede interna bloqueia acesso externo do worker

- **Contexto:** worker TSE conectado somente a uma rede Docker declarada com `internal: true`.
- **Sintoma:** o job falha com `Could not resolve host` embora o host e a VPS tenham acesso ao domínio externo.
- **Causa confirmada:** redes internas do Docker não fornecem rota de saída; o worker conseguia acessar o banco, mas não DNS ou HTTPS externos.
- **Correção:** manter banco e comunicação interna na rede protegida e adicionar uma rede `egress` separada aos serviços que precisam acessar APIs externas, sem publicar portas.
- **Validação:** resolver o host e baixar um recurso controlado dentro do worker; depois processar um job pela fila `tse` e confirmar `failed_jobs` limpo.
- **Prevenção:** declarar explicitamente quais serviços precisam de saída e testar conectividade de dentro do container antes de habilitar agendamentos.

## ERR-GIT-MODE-001 - Scripts perdem permissão de execução na release

- **Contexto:** scripts shell versionados no Windows e enviados à VPS por `git archive`.
- **Sintoma:** o arquivo existe, mas retorna `Permission denied` quando executado no Linux.
- **Causa confirmada:** o índice Git registrava o script como `100644`, então o arquivo era extraído sem bit executável.
- **Correção:** registrar scripts operacionais como `100755` no Git e reconstruir o pacote.
- **Validação:** conferir `git ls-files --stage scripts/` e executar os scripts em modo de validação na VPS.
- **Prevenção:** revisar o modo dos scripts em toda mudança de infraestrutura feita a partir do Windows.

## ERR-TENANT-001 - `firstOrCreate` ignora o gabinete protegido

- **Contexto:** criação automática de uma configuração vinculada a gabinete por um model que herda de `TenantModel`.
- **Sintoma:** a gravação falha com restrição `NOT NULL` em `gabinete_id`, embora o atributo tenha sido informado ao `firstOrCreate`.
- **Causa confirmada:** `TenantModel` protege `gabinete_id` contra atribuição em massa; o método descartou silenciosamente o atributo ao montar o novo registro.
- **Correção:** localizar sob transação e lock; quando ausente, instanciar o model e aplicar os campos controlados pelo servidor com `forceFill()` antes de salvar.
- **Validação:** abrir a administração como operador de plataforma, confirmar a criação de uma única configuração por gabinete e executar o teste de isolamento.
- **Prevenção:** não usar criação em massa para chaves de tenancy protegidas; atribuí-las somente a partir do contexto autorizado no backend.

## ERR-WHATSAPP-001 - Submissao de template ultrapassa o timeout privado

- **Contexto:** submissao administrativa de templates do GOVNEX GAB pelo Gateway WhatsApp.
- **Sintoma:** o GOVNEX GAB informa resultado ambiguo apos 20 segundos, enquanto templates anteriores do mesmo lote aparecem como pendentes na Meta.
- **Causa confirmada:** o timeout generico do cliente HMAC era menor que o tempo eventual de uma operacao administrativa envolvendo a Meta.
- **Correcao:** usar timeout de ate 60 segundos somente para submissao e sincronizacao de templates; o gateway deve responder com `ambiguous=true`, e o GOVNEX GAB deve persistir `SUBMISSION_AMBIGUOUS` antes de devolver o erro. Depois, sincronizar e usar a rotina auditavel do gateway, sem repetir automaticamente.
- **Validacao:** confirmar na Meta os itens aceitos, manter o item ausente como ambiguo durante a janela de seguranca e reabri-lo somente apos nova consulta e confirmacao explicita.
- **Prevencao:** submeter templates individualmente, reconciliar antes de qualquer retry e manter o timeout maior restrito a administracao, sem amplia-lo para mensagens.

## ERR-WHATSAPP-002 - Meta rejeita variavel no final efetivo do template

- **Contexto:** submissao de template Utility com um marcador seguido somente de pontuacao no fim do corpo.
- **Sintoma:** a Graph API retorna HTTP 400 com `Invalid parameter` e informa que variaveis nao podem aparecer no inicio ou no fim do modelo.
- **Causa confirmada:** a Meta considera `{{N}}.` uma variavel no final efetivo, mesmo com pontuacao depois do marcador.
- **Correcao:** acrescentar uma frase estatica significativa depois do ultimo marcador e atualizar o rascunho antes de submeter.
- **Validacao:** confirmar que o contrato local nao termina em marcador mais pontuacao e que a Meta aceita o template como `PENDING`.
- **Prevencao:** validar no catalogo tanto o inicio literal quanto o final efetivo dos corpos antes de criar ou alterar rascunhos.

## ERR-BUILD-001 - Wayfinder falha sem o cache de views

- **Contexto:** build imutavel de producao com `composer install --no-dev` seguido de `npm run build`.
- **Sintoma:** o Vite encerra com `Error generating types` ao executar `php artisan wayfinder:generate --with-form`.
- **Causa confirmada:** o Wayfinder inicializa o compilador Blade, mas `storage/framework/views` ainda nao existia na imagem.
- **Correcao:** criar os diretorios de runtime necessarios antes de instalar dependencias e gerar os assets.
- **Validacao:** executar o Wayfinder em ambiente sem dependencias de desenvolvimento e concluir o build multi-stage da imagem.
- **Prevencao:** preparar `bootstrap/cache` e `storage/framework/*` antes de qualquer comando Composer, Artisan ou Vite que inicialize a aplicacao.

## ERR-WORKTREE-001 - Pest perde o TestCase em worktree com vendor compartilhado

- **Contexto:** worktree Git independente usando `vendor` como junction para outro checkout no Windows.
- **Sintoma:** testes Pest baseados em closures recebem classes com o caminho absoluto no namespace e falham com helpers Laravel ausentes ou serviços como `config`, `files` e `filesystem` não encontrados.
- **Causa confirmada:** o Pest calcula a raiz pelos arquivos físicos do pacote em `vendor`; como o junction aponta para outro worktree, os testes ficam fora dessa raiz e a regra de `tests/Pest.php` não é aplicada ao namespace gerado.
- **Correção:** remover somente o junction validado e executar `composer install` no próprio worktree, mantendo um `vendor` local e coerente com seu autoload.
- **Validação:** executar a suíte completa e confirmar que os testes funcionais usam `Tests\TestCase`, sem namespaces derivados de caminho absoluto.
- **Prevenção:** não compartilhar `vendor` entre worktrees usados para executar Pest. Dependências podem ser reinstaladas pelo lockfile; caches globais do Composer permanecem reutilizáveis.

## ERR-LOCAL-PHP-001 - Build usa o PHP antigo do XAMPP no Wayfinder

- **Contexto:** desenvolvimento no Windows com PHP 8.2 do XAMPP no `PATH` e PHP 8.4 portátil usado pelo GOVNEX GAB.
- **Sintoma:** `npm run build` falha no plugin Wayfinder porque o subprocesso `php artisan wayfinder:generate` encontra PHP 8.2, embora Composer e testes tenham sido executados com PHP 8.4.
- **Causa confirmada:** o plugin invoca `php` pelo `PATH`; informar o executável 8.4 somente ao comando Composer não altera o PHP encontrado pelo processo Node.
- **Correção:** priorizar o diretório do PHP 8.4 no `PATH` da sessão antes de executar o build.
- **Validação:** executar `npm run build` e confirmar a geração das rotas e dos assets Vite.
- **Prevenção:** iniciar a sessão local do GOVNEX GAB com o PHP 8.4 antes do XAMPP no `PATH`; a imagem Docker continua sendo a referência reproduzível.

## ERR-CI-003 - Teste depende da disponibilidade da API do IBGE

- **Contexto:** teste de alteração do endereço do gabinete executado em runner sem acesso estável ao serviço de localidades do IBGE.
- **Sintoma:** a suíte falha após timeout HTTP, embora a regra e os dados de teste sejam válidos.
- **Causa confirmada:** a validação municipal consultava a API externa durante o teste sem uma resposta HTTP simulada.
- **Correção:** limpar o cache de localidades e simular a resposta do IBGE com os municípios usados em cada cenário.
- **Validação:** executar `tests/Feature/OfficeSettingsTest.php` sem acesso externo e depois confirmar a suíte no GitHub Actions.
- **Prevenção:** testes automatizados não devem depender de rede; toda integração externa precisa ser simulada ou explicitamente bloqueada.

## ERR-TENANT-002 - Parâmetros de contexto chegam ao recurso do controller

- **Contexto:** rota contextual com os prefixos `{entidade}` e `{gabinete}` envolvendo uma rota de recurso que também usa model binding.
- **Sintoma:** o middleware valida corretamente o contexto, mas o controller recebe a entidade ou o gabinete no argumento destinado ao recurso de negócio, produzindo `404`, erro de tipo ou consulta incorreta.
- **Causa confirmada:** depois de resolver o contexto, os parâmetros parentais continuavam no conjunto entregue pelo roteador ao despacho posicional do controller.
- **Correção:** o middleware valida, registra e injeta os contextos, depois remove somente os parâmetros parentais antes do controller; o model binding do recurso permanece intacto.
- **Validação:** executar a mesma rota com recurso em dois gabinetes, confirmar isolamento e verificar que o controller recebe o model de negócio correto.
- **Prevenção:** toda nova rota contextual com model binding filho deve possuir teste HTTP que alcance o controller e valide entidade, gabinete e recurso simultaneamente.

## Como adicionar um caso

1. confirme a causa com evidência;
2. aplique e valide uma correção reproduzível;
3. confirme que a prevenção será útil em outra sessão;
4. remova credenciais, dados pessoais e detalhes privados desnecessários;
5. atualize um caso existente quando a causa for a mesma.
