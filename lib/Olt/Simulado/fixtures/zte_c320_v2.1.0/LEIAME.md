# Fixtures — ZTE C320, MVR V2.1.0 (OLT de referência, 01/10/2026)

Saídas REAIS da CLI, coladas pelo Marcelo. Servem ao parser e ao driver simulado.
Anonimizadas: nome de cliente, SN e IP foram trocados mantendo exatamente o formato
(largura das colunas, maiúsculas, espaços). Não edite o layout: é ele que o parser lê.

| Arquivo | Comando |
|---|---|
| show_version_running.txt | `show version-running` |
| show_gpon_onu_state.txt | `show gpon onu state gpon-olt_1/1/1` |
| show_gpon_onu_detail_info.txt | `show gpon onu detail-info gpon-onu_1/1/1:1` |
| show_gpon_remote_onu_equip.txt | `show gpon remote-onu equip gpon-onu_1/1/1:1` |
| config_help_trecho.txt | `configure terminal` + `?` (trecho) |
| show_help_trecho.txt | `show ?` (em config, trecho) |
| file_help.txt | `file ?` |
| copy_help.txt | `copy ?` (não existe nesta versão) |
| ping_ok.txt | `ping <ip do FTP>` |
| ajuda_upgrade_rodada2.txt | rodada 2: `file download ?`, `remote-unit ?`, `show remote-unit ?`, `show gpon onu ?`... |
| show_gpon_onu_baseinfo.txt | `show gpon onu baseinfo gpon-olt_1/1/1` (rodada 3; HBR/FRKW = Furukawa bridge) |
| show_remote_unit_information.txt | `show remote-unit information gpon-olt_1/1/1 1` — **versão de software**: 2 bancos (Region), o ativo é o "Activated: Yes" |
| ajuda_rodada4.txt | rodada 4: `?` do remote-unit information, `remote-onu model`, `sys-attr` |
| show_remote_unit_information_lista.txt | `show remote-unit information gpon-olt_1/1/1 1-5` — **forma em lista** (inclui Furukawa; ONU 4 com banco 2 ativo) |
| show_gpon_remote_onu_equip_furukawa.txt | `show gpon remote-onu equip gpon-onu_1/1/1:2` numa **Furukawa 630-10B** (05/10): o OMCI responde; "Version" = HW `ZFK1.2A` |
| show_remote_unit_information_furukawa.txt | `show remote-unit information gpon-olt_1/1/1 2` na mesma Furukawa (05/10): RuType 630-10B, `V4.0.2` nos dois bancos |
| ajuda_upgrade_rodada5.txt | rodada 5: sintaxe do `file download version-ru` (senha na linha!), `remote-unit task/summary-of/update-status` |
| ajuda_ru_task_rodada6.txt | rodada 6: comandos do modo `remote-unit task` (o procedimento de upgrade) |
| ajuda_manual_rodada7.txt | rodada 7: `remote-unit ?` em EXEC = atualização MANUAL por ONU (update/activate/commit/abort) |
| ajuda_manual_rodada8.txt | rodada 8: sintaxe completa de `remote-unit update/activate/commit/abort` |
| update_status_inicio.txt | `show remote-unit update-status gpon-olt_1/1/2 71` logo após o `update` (1º upgrade real, 01/10): "unknown-ru", Action Unknown, 0% |
| update_status_transferindo.txt | idem, durante a transferência (Update, Remote, In-progress, 13%) |
| update_status_transferido.txt | idem, transferência terminada (Update, Success, 100%) |
| update_status_ativado.txt | idem, depois do `activate` (Activate, Local, Success) |
| update_status_commit.txt | idem, depois do `commit` (Commit, Success, Committime preenchido) |
| show_file_other_flash.txt | `show file other device flash` — traz a linha "Total disk size: N bytes (M bytes free)" (01/10: 24,9 MB livres de 126 MB) |
| show_file_version_ru_flash_vazia.txt | `show file version-ru device flash` sem imagens de ONU: NÃO mostra o espaço livre |
| summary_of_manual_erro_espaco.txt | `show remote-unit summary-of manual` após a falha da F6201B (30 MB): download para a flash da OLT recusado por falta de espaço; nome do arquivo em minúsculas |
| show_gpon_onu_state_pon_vazia.txt | `show gpon onu state gpon-olt_1/2/12` (PON desativada, 01/10): `%Code 62310-GPONSRV : No related information to show.` — PON vazia, NÃO é recusa |
| show_file_other_flash_com_imagem.txt | `show file other device flash` logo depois da 2ª atualização real: a imagem baixada do FTP fica em "other" (minúsculas) até o aging-time (padrão 30 min) |
| summary_of_manual_operando.txt | `show remote-unit summary-of manual` no piloto (02/10): "download successful" também aparece em Download; Operating = ONU recebendo agora; Success = upgrades anteriores |
