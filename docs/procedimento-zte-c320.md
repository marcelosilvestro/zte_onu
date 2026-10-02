# Procedimento de atualização — ZTE C320 (MVR V2.1.x)

Referência técnica do driver `zte_c320_v21`. Tudo aqui foi **visto na OLT real** (C320, MVR
V2.1.0, placas GTGH/GTGHK + SMXA). As saídas originais, anonimizadas, estão em
`lib/Olt/Simulado/fixtures/zte_c320_v2.1.0/` (índice em `LEIAME.md`) e alimentam a OLT simulada e
a suíte de testes.

## Resumo

- A atualização é **manual, ONU a ONU**, em três passos, todos no modo privilegiado (`#`, não em
  `(config)#`):
  1. `remote-unit update <arquivo> gpon-olt_1/<slot>/<pon> <onu> remote ftp ipaddress <ip> path <pasta> user <usuario> password <senha>`
  2. `remote-unit activate gpon-olt_1/<slot>/<pon> <onu>` — a ONU reinicia no banco novo (cliente fica ~1–2 min sem serviço)
  3. `remote-unit commit gpon-olt_1/<slot>/<pon> <onu>` — fixa a versão nova (sem isso, a ONU volta para a anterior no próximo reinício)
- Cada passo só é dado depois que a **leitura dos bancos da ONU** confirma o anterior:
  `show remote-unit information gpon-olt_1/<slot>/<pon> <onu>` (dois bancos, "Region 1/2", com
  `Vertag`, `Commited`, `Activated`, `Valid`).
- O job só conclui com a versão nova **ativa e confirmada (commit)**.
- `remote-unit abort gpon-olt_1/<slot>/<pon> <onu>` limpa uma tarefa parada. Em `#` responde
  vazio; em `(config)#` dá `%Error 20200`.

## A flash da OLT (o ponto mais importante)

No modo `remote`, a OLT **baixa o firmware inteiro do FTP para a própria flash** e só depois o
envia à ONU por OMCI.

- A imagem fica na pasta **`other`** da flash, com o nome em minúsculas:
  `show file other device flash`.
- A OLT apaga a imagem sozinha depois do **`remote-unit aging-time`** (configuração global,
  padrão **30 min**, "Only when ONU file downloaded from remote server").
- O espaço livre aparece em qualquer listagem de pasta **com arquivos**:
  `Total disk size: 132120576 bytes (26136576 bytes free)`. Pasta vazia ("No such files in
  master") não mostra a linha.
- Na OLT de referência: **126 MB de flash, ~24,9 MB livres**. A maior parte é o sistema da
  própria OLT (pasta `version`: MVR da controladora em duas cópias, ~49 MB, mais as imagens das
  placas de linha).
- Firmware maior que o espaço livre é recusado: `show remote-unit summary-of manual` mostra
  `file <nome> download error from remote server, Reason:Remain space not enough`, e o
  `update-status` fica parado em "unknown-ru / Unknown / In-progress / 0%", **sem motivo**.
- Duas transferências ao mesmo tempo não cabem (durante uma, sobraram 692 KB).

### O que o addon faz por causa disso

- Antes de enviar, lê o espaço livre (`show file other|version-ru|log-dbg|debug-file device
  flash`, só leitura) e **bloqueia** se `tamanho + 256 KB > livre`, na simulação, na prévia da
  atualização avulsa e no worker (que pausa a campanha sem enviar nada).
- Se a **mesma imagem** (nome e tamanho) já está em `other` (aging-time), não exige espaço novo.
- Configuração **"Transferências simultâneas por OLT"** (padrão 1).
- Durante a transferência, lê o `summary-of manual` uma vez por ciclo. Um erro de download
  **novo** para o arquivo do job vira falha imediata, com o motivo da OLT, seguida de
  `remote-unit abort`. A lista não tem data: o addon fotografa a lista antes do `update`
  (`tab_zte_job.resumo_antes`) e só considera o que aparecer depois.
- **Nunca apaga arquivos do sistema da OLT.** Liberar espaço (ex.: imagens de placas não
  instaladas) é manutenção do equipamento: backup com `file upload version <arq> ftp ...`,
  conferência do tamanho, e só então `file delete version <arq> device flash`. Nunca tocar nas
  imagens da controladora (`smxa*`) nem das placas instaladas — conferir com `show card` e
  `show version-running` (o tamanho do MVR em uso identifica o arquivo).

## Andamento e falhas

| Comando | Para que serve | Observação |
|---|---|---|
| `show remote-unit update-status gpon-olt_1/<s>/<p> <onu>` | % da transferência | Action Unknown→Update→Activate→Commit; Status In-progress/Success. Só para exibir |
| `show remote-unit summary-of manual` | falhas de download com motivo; listas Fail/Success | Sem data; nome do arquivo em minúsculas |
| `show remote-unit information gpon-olt_1/<s>/<p> <onu>` | versão nos dois bancos | **Decide** cada passo |

- O `commit` enviado **logo que a ONU volta** é aceito (resposta vazia) mas **não vale**; o
  seguinte vale na hora. Visto nos 3 upgrades reais (01–02/10). O addon espera o ciclo seguinte
  (≥45 s com a ONU de volta) para mandar o commit, e reenvia a cada 2 min sem confirmação, até 3
  vezes; depois disso, só o tempo limite do job (inconclusivo).
- Tempos observados: F670L (24,7 MB) transferência ~8 min; F6201B (28,6 MB) ~5 min; reinício
  ~1 min nas duas.

## Inventário

- `show gpon onu state gpon-olt_1/<s>/<p>` + `show gpon onu baseinfo ...` por PON;
  `detail-info` (nome = login do cliente) e `remote-onu equip` (modelo e revisão de HW) por ONU.
- Versão de software: `show remote-unit information gpon-olt_1/<s>/<p> <a>-<b>` (faixa num
  comando só).
- PON vazia ou desativada responde `%Code 62310-GPONSRV : No related information to show.` —
  é PON vazia, não erro.
- Uma porta recusada no meio da placa é pulada; recusa já na porta 1 = a placa não é de PON.
- ONUs Furukawa (SN `FRKW`) entram só com SN e nome e nunca são atualizadas.

## Segurança operacional

- A senha da conta FTP **da OLT** vai na linha de comando (exigência do próprio comando) e fica
  no histórico da OLT. No addon ela aparece sempre como `***`. Use para a OLT uma conta **só de
  leitura**, separada da conta do addon.
- Uma sessão por vez em cada OLT (trava por OLT); o inventário automático usa um login por OLT;
  o worker mostra quantos logins fez por ciclo.
- Driver **validado** em 02/10/2026 (3 ONUs isoladas + piloto de 2). Um driver "experimental"
  aprova no máximo 2 ONUs por campanha (o piloto). O modo seguro (ligado por padrão) limita a
  1 ONU, seja qual for o nível do driver.
- A OLT **reaproveita** a imagem já baixada: numa campanha, só a primeira ONU de cada firmware
  busca no FTP enquanto a imagem está na flash (aging-time); as seguintes vão direto ao envio.
