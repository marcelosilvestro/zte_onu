# ONUs ZTE — gestão de firmware para MK-AUTH (`zte_onu`)

Addon do MK-AUTH para inventário, gerenciamento e atualização de firmware de ONUs ZTE em
OLTs ZTE C320. Ele é um produto distribuível: nenhum IP, usuário, senha ou servidor FTP fica
no código, e tudo é cadastrado pela interface.

> **Estado: v0.10.1 (02/10/2026).** Todas as telas funcionam: OLTs, repositórios FTP, firmwares,
> inventário, topologia, regras, campanhas, fila, atualização avulsa pelo inventário,
> diagnóstico, auditoria e configurações. O driver da C320 (MVR V2.1.x) está **validado**:
> 5 ONUs atualizadas na OLT de referência (F670L e F6201B, avulsas e um piloto de 2 ONUs).
> O **modo seguro** (ligado por padrão) continua limitando a 1 ONU até o administrador
> desligá-lo. Detalhes do procedimento e das limitações da OLT em
> [docs/procedimento-zte-c320.md](docs/procedimento-zte-c320.md).

## Instalação rápida

No servidor MK-AUTH, **como root**, rode:

```sh
wget -O - https://raw.githubusercontent.com/marcelosilvestro/zte_onu/main/instalar.sh | bash
```

Pronto: o addon aparece no menu **PROVEDOR › ONUs ZTE - Firmware**. O mesmo comando também
**atualiza** para a versão mais nova e **repara** uma instalação existente, sem perder
configurações, senhas, inventário nem histórico.

Depois, abra o addon e siga o [Primeiro acesso](#primeiro-acesso).

**Requisitos:** MK-AUTH com PHP 8.0+ · OLT ZTE C320 com MVR V2.1.x acessível por telnet a partir
do MK-AUTH · um servidor FTP (o firmware fica nele; a OLT o busca direto de lá).

## Telas

| Tela | O que faz |
|---|---|
| Painel | ONUs online/offline/desatualizadas, distribuição de versões, campanhas ativas |
| OLTs | Cadastro, teste de acesso, identificação de versão e placas, driver e compatibilidade |
| Repositórios | FTP: teste em etapas, navegador de arquivos, sincronização; acesso de cada OLT ao FTP |
| Firmwares | Upload (SHA-256 na origem e conferido no FTP), adoção de arquivo já no FTP, compatibilidade modelo × HW |
| Inventário | ONUs por OLT/PON, filtros (inclusive "versão em uso"), botão **Atualizar agora** (técnico no local) |
| Topologia | OLT → placa → PON, com contagens e versões |
| Regras / Campanhas | Regra (modelo, HW, origem → firmware); campanha única ou **recorrente** (dias da semana + janela + teto por rodada; cada rodada vira uma campanha comum); excluir (sem execução) e arquivar |
| Fila | Jobs com estado, % da transferência, eventos com a saída da OLT, reprocessamento |
| Diagnóstico / Auditoria / Configurações | Saúde por componente, linha do tempo, limites e modo seguro |

## Como a atualização acontece

1. O **worker** (cron, a cada minuto, usuário www-data) pega os jobs aprovados.
2. Confere o arquivo no FTP e o **espaço na flash da OLT**.
3. Manda `remote-unit update ... remote ftp ...`: a OLT baixa do FTP para a própria flash e
   grava no banco inativo da ONU.
4. Lendo os bancos da ONU: versão no banco inativo → `activate` (ONU reinicia) → versão ativa →
   `commit` → concluído só com a versão nova **ativa e confirmada**.
5. Qualquer dúvida sobre o estado real da ONU vira **inconclusivo**, segura a ONU e pausa a
   campanha (disjuntor). Nada é repetido sem ler a ONU antes.

Papéis específicos: `onu.atualizar_avulso` libera o botão **Atualizar agora** do inventário
(cria, simula e aprova uma campanha de 1 ONU com o firmware mais novo compatível, sem janela).

## Arquitetura

```
Navegador ─HTTP─▶ addon (MK-AUTH) ──FTP/FTPS (credencial do ADDON)──▶ Servidor FTP externo
                     │                                                      ▲
                     └──Telnet/SSH──▶ OLT ZTE ──FTP (credencial da OLT)─────┘
                                       │ OMCI
                                       ▼
                                      ONUs
```

No upgrade, a OLT busca o firmware **direto no FTP**, sem o arquivo passar pelo MK-AUTH. O
addon só gerencia o repositório: upload, listagem, verificação e exclusão.

## Instalação (detalhes)

```sh
# instalar ou atualizar para a última versão (como root, no servidor MK-AUTH)
wget -O - https://raw.githubusercontent.com/marcelosilvestro/zte_onu/main/instalar.sh | bash

# só diagnosticar a instalação, sem mexer em nada
wget -O - https://raw.githubusercontent.com/marcelosilvestro/zte_onu/main/instalar.sh | bash -s -- --diagnostico

# instalar uma versão específica
wget -O - https://raw.githubusercontent.com/marcelosilvestro/zte_onu/main/instalar.sh | bash -s -- --versao=v0.10.1

# servidor sem acesso ao GitHub: baixe o pacote da página de Releases e instale localmente
bash instalar.sh --pacote=zte_onu-0.10.1.tar.gz
```

Outras opções: `--forcar` (reinstala mesmo atualizado), `--nao-interativo` (nunca pergunta;
para instalação automatizada, com `ZTE_DB_USER`/`ZTE_DB_PASS`), `--ajuda`.

O instalador:
- confere o PHP 8.0+ e as extensões `pdo_mysql`, `sodium`, `mbstring` e `ftp` ou `curl`
  (`openssl` só é necessária para FTPS);
- grava o acesso ao banco em `/opt/mk-auth/conf/zte_onu.php` (640 root:www-data);
- cria a **chave do cofre** em `/opt/mk-auth/conf/zte_onu.key` (640 root:www-data). Ela
  **nunca** é recriada. Guarde uma cópia junto do backup do banco: sem ela, as senhas de OLT e
  FTP precisam ser cadastradas de novo;
- cria `/opt/mk-auth/dados/zte_onu/` e `/opt/mk-auth/log/zte_onu/`;
- aplica o schema (`sql/baseline.sql`, idempotente), depois de um dump das tabelas `tab_zte_*`;
- acrescenta a linha do menu em `addons/addon.js` (PROVEDOR › ONUs ZTE - Firmware).

Atualizar é rodar o mesmo comando de novo. Configuração, chave, dados e logs ficam
fora da pasta do addon e são preservados.

Antes de cada atualização o instalador faz um dump das tabelas `tab_zte_*` em `/opt/mk-auth/bckp/zte_onu/`.

## Primeiro acesso

1. Abra o addon: enquanto não houver administrador, qualquer usuário do painel pode **assumir
   a administração** (uma única vez, e a ação fica na auditoria).
2. Em Configurações › Permissões, conceda papéis aos outros logins do MK-AUTH.
3. Siga a configuração inicial do Painel.

O **modo seguro** vem ligado: só é possível atualizar uma ONU por vez. Desligá-lo exige que o
administrador digite `DESLIGAR`, e a ação fica na auditoria.

## Segurança

- As senhas são cifradas com libsodium (XChaCha20-Poly1305) e cada ciframento fica amarrado
  ao seu dono. A chave fica fora do banco e fora da pasta do addon.
- As permissões são por operação e conferidas no servidor em toda chamada. Toda escrita exige
  token CSRF.
- Log, auditoria e pacote de suporte passam por mascaramento: os campos de senha e qualquer
  senha conhecida viram `***`.
- Não existe "comando livre" para a OLT, e todo parâmetro é validado antes de qualquer uso.

## Rede

| Origem → destino | Porta |
|---|---|
| MK-AUTH → OLT | 23 (telnet) ou 22 (SSH), conforme o cadastro |
| MK-AUTH → FTP | 21 + faixa passiva do servidor (990 para FTPS implícito) |
| OLT → FTP | 21 + dados, pela rede de gerência da OLT |

## Desenvolvimento

```sh
php tests/run.php --conf=/caminho/conf.php     # schema de teste próprio, recriado a cada execução
./empacotar.sh                                 # gera pacote/zte_onu-<versao>.tar.gz
```

A suíte recusa rodar de dentro de `/opt/mk-auth` e só aceita schema com `test` no nome. O
empacotador barra o pacote se encontrar IP ou caminho do ambiente de desenvolvimento.
