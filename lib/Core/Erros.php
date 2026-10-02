<?php
/**
 * zte_onu :: catalogo de codigos de erro estaveis.
 *
 * O codigo e contrato: interface, logs e testes dependem dele, nunca do texto.
 * A mensagem e a que o USUARIO ve — sem SQL, sem nome de tabela, sem senha, sem stack trace.
 */
final class Erros
{
    public const MENSAGENS = [
        // Sessao e acesso
        'ZTE-AUTH-001' => 'Sessão expirada.',
        'ZTE-AUTH-002' => 'Você não tem permissão para esta operação.',
        'ZTE-AUTH-003' => 'Requisição inválida (token de segurança).',
        'ZTE-AUTH-004' => 'O administrador do addon já foi definido.',
        'ZTE-AUTH-005' => 'O addon precisa ter ao menos um administrador.',
        'ZTE-AUTH-006' => 'Login não encontrado entre os usuários do MK-AUTH.',

        // Cofre de credenciais
        'ZTE-COF-001' => 'A chave do cofre de credenciais não foi encontrada no servidor. Rode o instalador.',
        'ZTE-COF-002' => 'Esta senha foi gravada com outra chave do cofre e precisa ser cadastrada de novo.',
        'ZTE-COF-003' => 'Não foi possível ler a senha guardada: o registro está corrompido. Cadastre de novo.',
        'ZTE-COF-004' => 'A chave do cofre é inválida.',

        // Validacao de entrada
        'ZTE-VAL-001' => 'Endereço (IP ou hostname) inválido.',
        'ZTE-VAL-002' => 'Porta inválida (1 a 65535).',
        'ZTE-VAL-003' => 'Nome de arquivo inválido: use letras, números, ponto, hífen e sublinhado.',
        'ZTE-VAL-004' => 'Caminho inválido ou fora do diretório permitido.',
        'ZTE-VAL-005' => 'Login inválido.',
        'ZTE-VAL-006' => 'Horário inválido (use HH:MM).',
        'ZTE-VAL-007' => 'Valor numérico fora da faixa permitida.',
        'ZTE-VAL-008' => 'Campo obrigatório não preenchido.',
        'ZTE-VAL-009' => 'Texto contém caracteres não permitidos.',

        // OLT
        'ZTE-OLT-001' => 'OLT não encontrada.',
        'ZTE-OLT-002' => 'Já existe uma OLT com este nome.',
        'ZTE-OLT-003' => 'A senha de acesso à OLT não foi cadastrada.',
        'ZTE-OLT-004' => 'Esta OLT tem histórico (inventário, regras, campanhas ou vínculo com FTP) e não pode ser removida. Desative-a.',
        'ZTE-OLT-005' => 'OLT bloqueada temporariamente após falhas de login seguidas.',
        'ZTE-OLT-006' => 'Não foi possível conectar à OLT (endereço ou porta inacessível).',
        'ZTE-OLT-007' => 'A OLT recusou o usuário ou a senha.',
        'ZTE-OLT-008' => 'A OLT não respondeu dentro do tempo limite.',
        'ZTE-OLT-009' => 'A resposta da OLT veio num formato que o driver não reconhece.',
        'ZTE-OLT-010' => 'Acesso por SSH ainda não é suportado nesta versão: use telnet.',
        'ZTE-OLT-011' => 'Esta OLT está desativada.',
        'ZTE-OLT-012' => 'Não há driver para este fabricante/modelo.',
        'ZTE-OLT-013' => 'A OLT entrou em modo usuário (>). Informe a senha de enable.',
        'ZTE-OLT-014' => 'Já existe uma operação em andamento nesta OLT. Aguarde e tente de novo.',
        'ZTE-OLT-015' => 'A conexão com a OLT caiu no meio da operação.',
        'ZTE-OLT-016' => 'A OLT respondeu com erro ao comando.',
        'ZTE-OLT-017' => 'Para remover, digite o nome exato da OLT.',
        'ZTE-OLT-018' => 'O procedimento de atualização de ONU desta OLT ainda não foi validado: o addon não executa upgrade nela.',

        // Repositorio FTP
        'ZTE-REP-001' => 'Repositório não encontrado.',
        'ZTE-REP-002' => 'Já existe um repositório com este nome.',
        'ZTE-REP-003' => 'A senha do addon neste FTP não foi cadastrada.',
        'ZTE-REP-004' => 'Este repositório tem firmwares ou acessos de OLT cadastrados e não pode ser removido. Desative-o.',
        'ZTE-REP-005' => 'Repositório bloqueado temporariamente após falhas de login seguidas.',
        'ZTE-REP-006' => 'Não foi possível conectar ao servidor FTP (endereço ou porta inacessível).',
        'ZTE-REP-007' => 'O servidor FTP recusou o usuário ou a senha.',
        'ZTE-REP-008' => 'O servidor FTP não respondeu dentro do tempo limite.',
        'ZTE-REP-009' => 'O diretório raiz não existe no FTP ou o usuário não tem acesso a ele.',
        'ZTE-REP-010' => 'Esta operação não está habilitada neste repositório.',
        'ZTE-REP-011' => 'Esta operação ainda não foi confirmada pelo teste do repositório. Teste o repositório primeiro.',
        'ZTE-REP-012' => 'O servidor FTP recusou a operação (permissão no servidor).',
        'ZTE-REP-013' => 'Este arquivo pertence a um firmware cadastrado e não pode ser alterado por aqui.',
        'ZTE-REP-014' => 'FTPS não está disponível neste servidor MK-AUTH (falta suporte a TLS no PHP).',
        'ZTE-REP-015' => 'Este repositório está desativado.',
        'ZTE-REP-016' => 'A conexão com o FTP caiu durante a transferência.',
        'ZTE-REP-017' => 'Para excluir, digite o nome exato.',
        'ZTE-REP-018' => 'Já existe uma operação em andamento neste repositório. Aguarde e tente de novo.',
        'ZTE-REP-019' => 'Arquivo ou pasta não encontrado no FTP.',
        'ZTE-REP-020' => 'Já existe um arquivo ou pasta com este nome.',
        'ZTE-REP-021' => 'O servidor FTP respondeu de um jeito que o addon não reconhece.',

        // Firmware
        'ZTE-FW-001' => 'Firmware não encontrado.',
        'ZTE-FW-002' => 'Tipo de arquivo não aceito como firmware.',
        'ZTE-FW-003' => 'Arquivo vazio ou acima do tamanho máximo permitido.',
        'ZTE-FW-004' => 'Já existe um arquivo com este nome nesta pasta do FTP. Escolha outro nome.',
        'ZTE-FW-005' => 'Já existe um firmware cadastrado neste caminho.',
        'ZTE-FW-006' => 'O repositório não está liberado para envio. Teste o repositório com envio e exclusão habilitados.',
        'ZTE-FW-007' => 'O envio ao FTP falhou e nada foi cadastrado.',
        'ZTE-FW-008' => 'O arquivo no FTP não confere com o original (SHA-256 ou tamanho diferente). O firmware foi marcado como inválido.',
        'ZTE-FW-009' => 'O arquivo do firmware não foi encontrado no FTP.',
        'ZTE-FW-010' => 'Este firmware é usado por regras ou campanhas: desative-o em vez de excluir.',
        'ZTE-FW-011' => 'Cadastre ao menos uma compatibilidade (modelo × revisão de hardware) antes de disponibilizar.',
        'ZTE-FW-012' => 'A integridade do arquivo no FTP ainda não foi verificada.',
        'ZTE-FW-013' => 'Este firmware não tem hash de origem independente: confirme explicitamente para disponibilizar.',
        'ZTE-FW-014' => 'Para excluir, digite o nome exato do arquivo.',
        'ZTE-FW-015' => 'SHA-256 informado inválido (64 caracteres hexadecimais).',
        'ZTE-FW-016' => 'O arquivo não chegou ao servidor (falha no envio pelo navegador).',
        'ZTE-FW-017' => 'Este firmware está desativado.',
        'ZTE-FW-018' => 'Modelo ou revisão de hardware inválidos.',
        'ZTE-FW-019' => 'Este firmware está em uma campanha em andamento e não pode ser alterado agora.',

        // Inventario
        'ZTE-INV-001' => 'A OLT ainda não foi identificada: teste o acesso antes de inventariar.',
        'ZTE-INV-002' => 'ONU não encontrada no inventário.',
        'ZTE-INV-003' => 'As PONs desta OLT ainda não foram descobertas.',

        // Regras
        'ZTE-REG-001' => 'Regra não encontrada.',
        'ZTE-REG-002' => 'Já existe uma regra com este nome.',
        'ZTE-REG-003' => 'O firmware escolhido não declara compatibilidade com este modelo e revisão de hardware.',
        'ZTE-REG-004' => 'Firmware inexistente ou desativado.',
        'ZTE-REG-005' => 'Esta regra está em uma campanha em andamento e não pode ser alterada agora.',
        'ZTE-REG-006' => 'Esta regra já foi usada em campanhas: desative-a em vez de remover.',
        'ZTE-REG-007' => 'Informe ao menos uma revisão de hardware aceita.',
        'ZTE-REG-008' => 'Versão de origem inválida.',

        // Campanhas
        'ZTE-CAM-001' => 'Campanha não encontrada.',
        'ZTE-CAM-002' => 'Esta operação não é permitida no estado atual da campanha.',
        'ZTE-CAM-003' => 'A simulação está vencida: simule de novo antes de aprovar.',
        'ZTE-CAM-004' => 'Há verificações bloqueando a aprovação.',
        'ZTE-CAM-005' => 'O escopo mudou desde a simulação (inventário ou outra campanha). Revise a nova simulação antes de aprovar.',
        'ZTE-CAM-006' => 'Quem criou a campanha não pode aprová-la (separação entre criar e aprovar).',
        'ZTE-CAM-007' => 'Confirme a ciência de que o acesso da OLT ao FTP ainda não foi comprovado.',
        'ZTE-CAM-008' => 'Nenhuma ONU elegível: não há o que aprovar.',
        'ZTE-CAM-009' => 'Para abortar, digite o nome exato da campanha.',
        'ZTE-CAM-010' => 'A regra escolhida é de outra OLT.',
        'ZTE-CAM-011' => 'Modo seguro ligado: só é possível aprovar campanha de exatamente 1 ONU.',
        'ZTE-CAM-012' => 'A regra está desativada.',
        'ZTE-CAM-013' => 'Janela de execução inválida (início e fim iguais).',
        'ZTE-CAM-014' => 'A verificação do firmware antes da aprovação falhou.',
        'ZTE-CAM-015' => 'Atualização avulsa não é editável: aborte e crie outra pelo inventário.',
        'ZTE-CAM-016' => 'Escolha pelo menos um dia da semana para a campanha recorrente.',
        'ZTE-CAM-017' => 'Esta campanha já enviou comandos à OLT: ela não pode ser excluída, só arquivada (o histórico fica preservado).',
        'ZTE-CAM-018' => 'Só campanhas concluídas ou abortadas podem ser arquivadas.',
        'ZTE-CAM-019' => 'Rodadas são criadas pela campanha recorrente: edite a recorrente (pause, edite, simule e aprove).',
        'ZTE-CAM-020' => 'A regra ou o firmware mudou desde a aprovação: a campanha recorrente voltou a rascunho. Simule e aprove de novo.',

        // Atualizacao avulsa (inventario)
        'ZTE-AVU-001' => 'Só ONU ZTE, presente na OLT e com modelo e versão lidos pode ser atualizada.',
        'ZTE-AVU-002' => 'Não há firmware disponível e compatível mais novo que a versão desta ONU.',
        'ZTE-AVU-003' => 'A ONU está offline: a atualização avulsa é para o técnico no local, com a ONU ligada.',
        'ZTE-AVU-004' => 'Esta ONU já está numa atualização em andamento.',

        // Jobs
        'ZTE-JOB-001' => 'Job não encontrado.',
        'ZTE-JOB-002' => 'Transição de estado não permitida para este job.',
        'ZTE-JOB-003' => 'Este job não pode ser reprocessado (estado do job ou da campanha).',
        'ZTE-JOB-004' => 'A ONU já está em outro job ativo.',

        // Acesso da OLT ao FTP
        'ZTE-VIN-001' => 'Acesso da OLT ao repositório não encontrado.',
        'ZTE-VIN-002' => 'Esta OLT já tem um acesso cadastrado para este repositório.',
        'ZTE-VIN-003' => 'O endereço do FTP visto pela OLT precisa ser um IP.',
        'ZTE-VIN-004' => 'O driver desta OLT não tem teste de acesso ao FTP.',
        'ZTE-VIN-005' => 'A senha que a OLT usa no FTP não foi cadastrada.',
        'ZTE-VIN-006' => 'A pasta do firmware vista pela OLT precisa ter de 3 a 128 caracteres (ex.: /firmware) e só letras, números, ponto, hífen, sublinhado e barra.',

        // Configuracao
        'ZTE-CFG-001' => 'Configuração desconhecida.',
        'ZTE-CFG-002' => 'Esta configuração não pode ser alterada pela interface.',

        // Concorrencia
        'ZTE-CONC-001' => 'Este registro foi alterado por outro usuário. Recarregue antes de salvar.',

        // Sistema
        'ZTE-SYS-001' => 'Não foi possível concluir a operação.',
        'ZTE-SYS-002' => 'Dados inválidos na requisição.',
        'ZTE-SYS-003' => 'Operação desconhecida.',
        'ZTE-SYS-004' => 'Método HTTP não permitido para esta operação.',
        'ZTE-SYS-005' => 'Erro de configuração: banco de dados não configurado. Rode o instalador.',
        'ZTE-SYS-006' => 'O banco do addon está incompleto ou desatualizado. Rode o instalador.',
    ];

    public static function mensagem(string $code): string
    {
        return self::MENSAGENS[$code] ?? self::MENSAGENS['ZTE-SYS-001'];
    }

    public static function existe(string $code): bool
    {
        return isset(self::MENSAGENS[$code]);
    }
}
