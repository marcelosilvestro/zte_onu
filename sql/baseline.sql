-- zte_onu :: schema completo, idempotente.
--
-- Roda inteiro a cada instalacao/atualizacao (lib/Core/Schema.php): o que ja existe fica,
-- o que falta e criado. Mudanca de coluna futura entra aqui como ALTER condicional, nunca
-- como arquivo incremental solto.
--
-- Regras de escrita deste arquivo (o separador de comandos e simples):
--   * todo comando termina com ";" no FIM da linha
--   * comentario so em linha propria comecando com "--", nunca depois do ";"
--
-- Tabelas nativas do MK-AUTH (sis_acesso, sis_cliente) sao somente leitura e nao aparecem aqui.

CREATE TABLE IF NOT EXISTS `tab_zte_migration` (
  `migration`   VARCHAR(100) NOT NULL,
  `checksum`    CHAR(64)     NOT NULL,
  `versao`      VARCHAR(20)  NOT NULL DEFAULT '0',
  `executed_at` DATETIME     NOT NULL,
  `executed_by` VARCHAR(60)  NULL,
  `duracao_ms`  INT UNSIGNED NULL,
  `resultado`   VARCHAR(10)  NOT NULL DEFAULT 'ok',
  `erro`        TEXT         NULL,
  PRIMARY KEY (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Configuracao geral chave/valor. Os padroes vivem em lib/Core/Config.php: aqui so fica o
-- que o administrador alterou (e os valores internos, como a digital do cofre).
CREATE TABLE IF NOT EXISTS `tab_zte_config` (
  `chave`        VARCHAR(64) NOT NULL,
  `valor`        TEXT        NULL,
  `alterado_por` VARCHAR(60) NULL,
  `alterado_em`  DATETIME    NULL,
  PRIMARY KEY (`chave`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Papeis por login do MK-AUTH (sis_acesso.login). Um login tem quantos papeis precisar.
CREATE TABLE IF NOT EXISTS `tab_zte_permissao` (
  `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `login`      VARCHAR(60)  NOT NULL,
  `papel`      VARCHAR(40)  NOT NULL,
  `criado_por` VARCHAR(60)  NULL,
  `criado_em`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_login_papel` (`login`, `papel`),
  KEY `ix_papel` (`papel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tab_zte_auditoria` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `criado_em`   DATETIME        NOT NULL,
  `usuario`     VARCHAR(60)     NOT NULL,
  `ip`          VARCHAR(45)     NULL,
  `acao`        VARCHAR(60)     NOT NULL,
  `entidade`    VARCHAR(40)     NOT NULL,
  `entidade_id` BIGINT UNSIGNED NULL,
  `antes`       MEDIUMTEXT      NULL,
  `depois`      MEDIUMTEXT      NULL,
  `correlacao`  VARCHAR(64)     NULL,
  `request_id`  VARCHAR(32)     NULL,
  PRIMARY KEY (`id`),
  KEY `ix_criado` (`criado_em`),
  KEY `ix_entidade` (`entidade`, `entidade_id`),
  KEY `ix_usuario` (`usuario`),
  KEY `ix_correlacao` (`correlacao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cofre: senhas cifradas (libsodium XChaCha20-Poly1305), separadas dos registros operacionais.
-- A chave mora em /opt/mk-auth/conf/zte_onu.key, nunca no banco. digital_chave diz com qual
-- chave cada linha foi cifrada: se a chave do servidor mudar, o diagnostico aponta quais
-- credenciais precisam ser recadastradas.
CREATE TABLE IF NOT EXISTS `tab_zte_credencial` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `tipo`          VARCHAR(20)  NOT NULL,
  `dono_id`       INT UNSIGNED NOT NULL,
  `cifrado`       TEXT         NOT NULL,
  `digital_chave` CHAR(16)     NOT NULL,
  `alterado_por`  VARCHAR(60)  NULL,
  `alterado_em`   DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tipo_dono` (`tipo`, `dono_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- OLTs. Senha e senha de enable ficam no cofre (tipo 'olt' e 'olt_enable').
-- versao/placas/identificador sao DETECTADOS no teste, nunca digitados.
CREATE TABLE IF NOT EXISTS `tab_zte_olt` (
  `id`                     INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome`                   VARCHAR(80)  NOT NULL,
  `fabricante`             VARCHAR(40)  NOT NULL DEFAULT 'ZTE',
  `modelo`                 VARCHAR(40)  NOT NULL DEFAULT 'C320',
  `host`                   VARCHAR(253) NOT NULL,
  `porta`                  SMALLINT UNSIGNED NOT NULL DEFAULT 23,
  `protocolo`              ENUM('telnet','ssh','simulado') NOT NULL DEFAULT 'telnet',
  `usuario`                VARCHAR(60)  NOT NULL,
  `timeout_conexao_s`      SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  `timeout_comando_s`      SMALLINT UNSIGNED NOT NULL DEFAULT 30,
  `driver`                 VARCHAR(60)  NULL,
  `versao_detectada`       VARCHAR(60)  NULL,
  `placas_detectadas`      TEXT         NULL,
  `identificador_detectado` VARCHAR(120) NULL,
  `compatibilidade`        ENUM('desconhecida','validada','somente_leitura','nao_suportada') NOT NULL DEFAULT 'desconhecida',
  `ultimo_teste_em`        DATETIME     NULL,
  `ultimo_teste_resultado` ENUM('ok','aviso','erro') NULL,
  `ultimo_teste_detalhe`   VARCHAR(500) NULL,
  `falhas_auth`            TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `bloqueado_ate`          DATETIME     NULL,
  `observacao`             VARCHAR(500) NULL,
  `ativo`                  TINYINT(1)   NOT NULL DEFAULT 1,
  `versao`                 INT UNSIGNED NOT NULL DEFAULT 1,
  `criado_por`             VARCHAR(60)  NULL,
  `criado_em`              DATETIME     NOT NULL,
  `alterado_por`           VARCHAR(60)  NULL,
  `alterado_em`            DATETIME     NULL,
  `desativado_em`          DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 0.2.0: protocolo 'simulado' (OLT de demonstracao alimentada pelos fixtures). MODIFY e
-- idempotente: em instalacao nova nao muda nada; na 0.1.0 acrescenta o valor ao ENUM.
ALTER TABLE `tab_zte_olt` MODIFY `protocolo` ENUM('telnet','ssh','simulado') NOT NULL DEFAULT 'telnet';

-- Repositorios FTP externos. A senha do ADDON fica no cofre (tipo 'repo').
-- perm_* = o que o administrador habilitou; confirmado_* = o que o ultimo teste comprovou
-- (NULL = ainda nao testado). O addon so oferece a operacao com os dois ligados.
CREATE TABLE IF NOT EXISTS `tab_zte_repositorio` (
  `id`                      INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome`                    VARCHAR(80)  NOT NULL,
  `host`                    VARCHAR(253) NOT NULL,
  `porta`                   SMALLINT UNSIGNED NOT NULL DEFAULT 21,
  `protocolo`               ENUM('ftp','ftps_explicito','ftps_implicito') NOT NULL DEFAULT 'ftp',
  `passivo`                 TINYINT(1)   NOT NULL DEFAULT 1,
  `raiz`                    VARCHAR(255) NOT NULL DEFAULT '/',
  `usuario`                 VARCHAR(60)  NOT NULL,
  `timeout_conexao_s`       SMALLINT UNSIGNED NOT NULL DEFAULT 10,
  `timeout_transferencia_s` SMALLINT UNSIGNED NOT NULL DEFAULT 300,
  `perm_listar`             TINYINT(1)   NOT NULL DEFAULT 1,
  `perm_enviar`             TINYINT(1)   NOT NULL DEFAULT 1,
  `perm_renomear`           TINYINT(1)   NOT NULL DEFAULT 1,
  `perm_excluir`            TINYINT(1)   NOT NULL DEFAULT 0,
  `confirmado_listar`       TINYINT(1)   NULL,
  `confirmado_enviar`       TINYINT(1)   NULL,
  `confirmado_renomear`     TINYINT(1)   NULL,
  `confirmado_excluir`      TINYINT(1)   NULL,
  `ultimo_teste_em`         DATETIME     NULL,
  `ultimo_teste_resultado`  ENUM('ok','aviso','erro') NULL,
  `ultimo_teste_detalhe`    VARCHAR(500) NULL,
  `falhas_auth`             TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `bloqueado_ate`           DATETIME     NULL,
  `observacao`              VARCHAR(500) NULL,
  `ativo`                   TINYINT(1)   NOT NULL DEFAULT 1,
  `versao`                  INT UNSIGNED NOT NULL DEFAULT 1,
  `criado_por`              VARCHAR(60)  NULL,
  `criado_em`               DATETIME     NOT NULL,
  `alterado_por`            VARCHAR(60)  NULL,
  `alterado_em`             DATETIME     NULL,
  `desativado_em`           DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_nome` (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Como a OLT enxerga o repositorio: host/porta/caminho podem diferir dos do addon (outra
-- VLAN, NAT) e a credencial e outra (cofre, tipo 'olt_repo', dono = id desta linha).
CREATE TABLE IF NOT EXISTS `tab_zte_olt_repositorio` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `olt_id`              INT UNSIGNED NOT NULL,
  `repositorio_id`      INT UNSIGNED NOT NULL,
  `host_olt`            VARCHAR(253) NOT NULL,
  `porta_olt`           SMALLINT UNSIGNED NOT NULL DEFAULT 21,
  `usuario_olt`         VARCHAR(60)  NOT NULL,
  `caminho_olt`         VARCHAR(255) NOT NULL DEFAULT '/',
  `estado_conectividade` ENUM('desconhecido','nao_testavel','potencial','validado','falhou') NOT NULL DEFAULT 'desconhecido',
  `estado_em`           DATETIME     NULL,
  `estado_detalhe`      VARCHAR(500) NULL,
  `versao`              INT UNSIGNED NOT NULL DEFAULT 1,
  `criado_por`          VARCHAR(60)  NULL,
  `criado_em`           DATETIME     NOT NULL,
  `alterado_por`        VARCHAR(60)  NULL,
  `alterado_em`         DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_olt_repo` (`olt_id`, `repositorio_id`),
  KEY `ix_repo` (`repositorio_id`),
  CONSTRAINT `fk_zte_oltrepo_olt`  FOREIGN KEY (`olt_id`)         REFERENCES `tab_zte_olt` (`id`),
  CONSTRAINT `fk_zte_oltrepo_repo` FOREIGN KEY (`repositorio_id`) REFERENCES `tab_zte_repositorio` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Historico de todos os testes de conectividade, etapa por etapa.
CREATE TABLE IF NOT EXISTS `tab_zte_teste_conectividade` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `componente` ENUM('addon_olt','addon_ftp','olt_ftp','pre_requisito') NOT NULL,
  `alvo_tipo`  VARCHAR(20)  NOT NULL,
  `alvo_id`    INT UNSIGNED NULL,
  `etapa`      VARCHAR(40)  NOT NULL,
  `resultado`  ENUM('ok','aviso','erro','nao_testavel') NOT NULL,
  `detalhe`    VARCHAR(500) NULL,
  `duracao_ms` INT UNSIGNED NULL,
  `correlacao` VARCHAR(64)  NULL,
  `criado_por` VARCHAR(60)  NULL,
  `criado_em`  DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_alvo` (`componente`, `alvo_tipo`, `alvo_id`, `criado_em`),
  KEY `ix_correlacao` (`correlacao`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Biblioteca de firmwares. O estado segue a cadeia
--   hash_origem_calculado -> enviado -> integridade_remota_verificada -> disponivel
-- e so 'disponivel' entra em campanha.
CREATE TABLE IF NOT EXISTS `tab_zte_firmware` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `fabricante`      VARCHAR(40)  NOT NULL DEFAULT 'ZTE',
  `modelo_familia`  VARCHAR(60)  NOT NULL,
  `versao_firmware` VARCHAR(80)  NOT NULL,
  `nome_original`   VARCHAR(160) NULL,
  `nome_remoto`     VARCHAR(120) NOT NULL,
  `repositorio_id`  INT UNSIGNED NOT NULL,
  `caminho_remoto`  VARCHAR(255) NOT NULL,
  `tamanho_bytes`   BIGINT UNSIGNED NULL,
  `sha256_origem`   CHAR(64)     NULL,
  `sha256_remoto`   CHAR(64)     NULL,
  `hash_sem_origem` TINYINT(1)   NOT NULL DEFAULT 0,
  `estado`          ENUM('hash_origem_calculado','enviado','integridade_remota_verificada','disponivel',
                         'invalido','incompativel','ausente_no_ftp','desativado') NOT NULL,
  `verificado_em`   DATETIME     NULL,
  `observacao`      VARCHAR(500) NULL,
  `versao`          INT UNSIGNED NOT NULL DEFAULT 1,
  `criado_por`      VARCHAR(60)  NULL,
  `criado_em`       DATETIME     NOT NULL,
  `alterado_por`    VARCHAR(60)  NULL,
  `alterado_em`     DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_repo_caminho` (`repositorio_id`, `caminho_remoto`),
  KEY `ix_modelo` (`modelo_familia`, `versao_firmware`),
  CONSTRAINT `fk_zte_fw_repo` FOREIGN KEY (`repositorio_id`) REFERENCES `tab_zte_repositorio` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Compatibilidade explicita modelo x revisao de hardware. Sem curinga: firmware errado
-- inutiliza a ONU, entao cada combinacao aceita e cadastrada.
CREATE TABLE IF NOT EXISTS `tab_zte_firmware_compat` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `firmware_id` INT UNSIGNED NOT NULL,
  `modelo`      VARCHAR(60)  NOT NULL,
  `hw_versao`   VARCHAR(60)  NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fw_modelo_hw` (`firmware_id`, `modelo`, `hw_versao`),
  KEY `ix_modelo_hw` (`modelo`, `hw_versao`),
  CONSTRAINT `fk_zte_fwc_fw` FOREIGN KEY (`firmware_id`) REFERENCES `tab_zte_firmware` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tab_zte_firmware_verificacao` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `firmware_id`      INT UNSIGNED NOT NULL,
  `tipo`             ENUM('upload','reverificacao','sincronizacao','pre_campanha','pre_lote') NOT NULL,
  `resultado`        ENUM('ok','aviso','erro') NOT NULL,
  `tamanho_remoto`   BIGINT UNSIGNED NULL,
  `sha256_calculado` CHAR(64)     NULL,
  `detalhe`          VARCHAR(500) NULL,
  `correlacao`       VARCHAR(64)  NULL,
  `criado_por`       VARCHAR(60)  NULL,
  `criado_em`        DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_fw` (`firmware_id`, `criado_em`),
  CONSTRAINT `fk_zte_fwv_fw` FOREIGN KEY (`firmware_id`) REFERENCES `tab_zte_firmware` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tab_zte_sincronizacao` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `repositorio_id` INT UNSIGNED NOT NULL,
  `iniciado_em`    DATETIME     NOT NULL,
  `concluido_em`   DATETIME     NULL,
  `resultado`      ENUM('ok','aviso','erro') NULL,
  `total_remoto`   INT UNSIGNED NULL,
  `orfaos`         INT UNSIGNED NULL,
  `ausentes`       INT UNSIGNED NULL,
  `detalhe`        VARCHAR(500) NULL,
  `criado_por`     VARCHAR(60)  NULL,
  PRIMARY KEY (`id`),
  KEY `ix_repo` (`repositorio_id`, `iniciado_em`),
  CONSTRAINT `fk_zte_sync_repo` FOREIGN KEY (`repositorio_id`) REFERENCES `tab_zte_repositorio` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tab_zte_sincronizacao_item` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sincronizacao_id` INT UNSIGNED NOT NULL,
  `tipo`             ENUM('orfao_remoto','ausente_no_ftp','tamanho_divergente') NOT NULL,
  `caminho`          VARCHAR(255) NOT NULL,
  `firmware_id`      INT UNSIGNED NULL,
  `tamanho`          BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  KEY `ix_sync` (`sincronizacao_id`),
  CONSTRAINT `fk_zte_synci_sync` FOREIGN KEY (`sincronizacao_id`) REFERENCES `tab_zte_sincronizacao` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Inventario: uma linha por posicao fisica da ONU na OLT.
CREATE TABLE IF NOT EXISTS `tab_zte_onu` (
  `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `olt_id`        INT UNSIGNED NOT NULL,
  `slot`          TINYINT UNSIGNED NOT NULL,
  `porta`         TINYINT UNSIGNED NOT NULL,
  `onu_num`       SMALLINT UNSIGNED NOT NULL,
  `sn`            VARCHAR(40)  NULL,
  `modelo`        VARCHAR(60)  NULL,
  `hw_versao`     VARCHAR(60)  NULL,
  `sw_versao`     VARCHAR(80)  NULL,
  `estado`        ENUM('online','offline','desconhecido') NOT NULL DEFAULT 'desconhecido',
  `nome`          VARCHAR(120) NULL,
  `descricao`     VARCHAR(255) NULL,
  `login_cliente` VARCHAR(60)  NULL,
  `visto_em`      DATETIME     NULL,
  `atualizado_em` DATETIME     NOT NULL,
  `ausente_desde` DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_posicao` (`olt_id`, `slot`, `porta`, `onu_num`),
  KEY `ix_sn` (`sn`),
  KEY `ix_modelo_versao` (`modelo`, `hw_versao`, `sw_versao`),
  KEY `ix_login` (`login_cliente`),
  CONSTRAINT `fk_zte_onu_olt` FOREIGN KEY (`olt_id`) REFERENCES `tab_zte_olt` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 0.5.0: inventario real. fornecedor = prefixo do SN (ZTEG, FRKW...); tipo_perfil = "Type" da
-- OLT (HRT, HBR — perfil de cadastro, NAO e o modelo); fase = Phase State (working, DyingGasp,
-- LOS...); detalhe_em = ultima leitura de detail-info/equip.
ALTER TABLE `tab_zte_onu` ADD COLUMN IF NOT EXISTS `fornecedor` VARCHAR(10) NULL AFTER `sn`;
ALTER TABLE `tab_zte_onu` ADD COLUMN IF NOT EXISTS `tipo_perfil` VARCHAR(40) NULL AFTER `fornecedor`;
ALTER TABLE `tab_zte_onu` ADD COLUMN IF NOT EXISTS `fase` VARCHAR(20) NULL AFTER `estado`;
ALTER TABLE `tab_zte_onu` ADD COLUMN IF NOT EXISTS `detalhe_em` DATETIME NULL AFTER `visto_em`;
-- 0.6.0: versao de software (show remote-unit information): a do banco ativo fica em sw_versao,
-- a do outro banco em sw_standby.
ALTER TABLE `tab_zte_onu` ADD COLUMN IF NOT EXISTS `sw_standby` VARCHAR(80) NULL AFTER `sw_versao`;
ALTER TABLE `tab_zte_onu` ADD COLUMN IF NOT EXISTS `sw_lido_em` DATETIME NULL AFTER `sw_standby`;
ALTER TABLE `tab_zte_olt` ADD COLUMN IF NOT EXISTS `pons_detectadas` TEXT NULL AFTER `placas_detectadas`;
ALTER TABLE `tab_zte_olt` ADD COLUMN IF NOT EXISTS `inventario_em` DATETIME NULL AFTER `pons_detectadas`;

CREATE TABLE IF NOT EXISTS `tab_zte_onu_snapshot` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `olt_id`     INT UNSIGNED NOT NULL,
  `tirado_em`  DATETIME     NOT NULL,
  `total`      INT UNSIGNED NOT NULL,
  `online`     INT UNSIGNED NOT NULL,
  `offline`    INT UNSIGNED NOT NULL,
  `por_versao` MEDIUMTEXT   NULL,
  PRIMARY KEY (`id`),
  KEY `ix_olt_data` (`olt_id`, `tirado_em`),
  CONSTRAINT `fk_zte_snap_olt` FOREIGN KEY (`olt_id`) REFERENCES `tab_zte_olt` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Regra: so compatibilidade e selecao. Nao executa nada.
-- hw_aceitos e versoes_origem sao listas JSON; olt_id NULL = vale para qualquer OLT.
CREATE TABLE IF NOT EXISTS `tab_zte_regra` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome`           VARCHAR(80)  NOT NULL,
  `olt_id`         INT UNSIGNED NULL,
  `modelo`         VARCHAR(60)  NOT NULL,
  `hw_aceitos`     TEXT         NOT NULL,
  `versoes_origem` TEXT         NOT NULL,
  `firmware_id`    INT UNSIGNED NOT NULL,
  `ativo`          TINYINT(1)   NOT NULL DEFAULT 1,
  `observacao`     VARCHAR(500) NULL,
  `versao`         INT UNSIGNED NOT NULL DEFAULT 1,
  `criado_por`     VARCHAR(60)  NULL,
  `criado_em`      DATETIME     NOT NULL,
  `alterado_por`   VARCHAR(60)  NULL,
  `alterado_em`    DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_nome` (`nome`),
  CONSTRAINT `fk_zte_regra_olt` FOREIGN KEY (`olt_id`)      REFERENCES `tab_zte_olt` (`id`),
  CONSTRAINT `fk_zte_regra_fw`  FOREIGN KEY (`firmware_id`) REFERENCES `tab_zte_firmware` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Campanha: a execucao autorizada. O escopo e congelado em tab_zte_campanha_onu na aprovacao.
CREATE TABLE IF NOT EXISTS `tab_zte_campanha` (
  `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `uuid`             CHAR(36)     NOT NULL,
  `nome`             VARCHAR(120) NOT NULL,
  `olt_id`           INT UNSIGNED NOT NULL,
  `regra_id`         INT UNSIGNED NOT NULL,
  `firmware_id`      INT UNSIGNED NOT NULL,
  `estado`           ENUM('rascunho','simulada','aprovada','executando','pausada','concluida','abortada') NOT NULL DEFAULT 'rascunho',
  `escopo`           TEXT         NULL,
  `max_por_pon`      SMALLINT UNSIGNED NOT NULL,
  `max_concorrentes` SMALLINT UNSIGNED NOT NULL,
  `max_falhas`       SMALLINT UNSIGNED NOT NULL,
  `max_falhas_pct`   TINYINT UNSIGNED  NOT NULL,
  `retentativas`     TINYINT UNSIGNED  NOT NULL,
  `janela_inicio`    TIME         NOT NULL,
  `janela_fim`       TIME         NOT NULL,
  `simulacao`        MEDIUMTEXT   NULL,
  `simulada_em`      DATETIME     NULL,
  `simulada_por`     VARCHAR(60)  NULL,
  `ciencia_olt_ftp`  TINYINT(1)   NOT NULL DEFAULT 0,
  `aprovada_em`      DATETIME     NULL,
  `aprovada_por`     VARCHAR(60)  NULL,
  `iniciada_em`      DATETIME     NULL,
  `concluida_em`     DATETIME     NULL,
  `pausa_motivo`     VARCHAR(500) NULL,
  `versao`           INT UNSIGNED NOT NULL DEFAULT 1,
  `criado_por`       VARCHAR(60)  NULL,
  `criado_em`        DATETIME     NOT NULL,
  `alterado_por`     VARCHAR(60)  NULL,
  `alterado_em`      DATETIME     NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_uuid` (`uuid`),
  KEY `ix_estado` (`estado`),
  CONSTRAINT `fk_zte_camp_olt`   FOREIGN KEY (`olt_id`)      REFERENCES `tab_zte_olt` (`id`),
  CONSTRAINT `fk_zte_camp_regra` FOREIGN KEY (`regra_id`)    REFERENCES `tab_zte_regra` (`id`),
  CONSTRAINT `fk_zte_camp_fw`    FOREIGN KEY (`firmware_id`) REFERENCES `tab_zte_firmware` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tab_zte_campanha_onu` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campanha_id`       INT UNSIGNED NOT NULL,
  `onu_id`            INT UNSIGNED NOT NULL,
  `slot`              TINYINT UNSIGNED NOT NULL,
  `porta`             TINYINT UNSIGNED NOT NULL,
  `sw_versao_inicial` VARCHAR(80)  NULL,
  `hw_versao`         VARCHAR(60)  NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_camp_onu` (`campanha_id`, `onu_id`),
  KEY `ix_onu` (`onu_id`),
  CONSTRAINT `fk_zte_campo_camp` FOREIGN KEY (`campanha_id`) REFERENCES `tab_zte_campanha` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_zte_campo_onu`  FOREIGN KEY (`onu_id`)      REFERENCES `tab_zte_onu` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Job: uma ONU dentro de uma campanha.
--   uq_camp_onu  -> a mesma campanha nunca cria dois jobs para a mesma ONU
--   uq_onu_ativa -> nunca ha dois jobs ATIVOS para a mesma ONU, em campanhas diferentes
--                  (onu_ativa = onu_id enquanto o job nao terminou; NULL depois)
CREATE TABLE IF NOT EXISTS `tab_zte_job` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `campanha_id`     INT UNSIGNED NOT NULL,
  `onu_id`          INT UNSIGNED NOT NULL,
  `onu_ativa`       INT UNSIGNED NULL,
  `estado`          ENUM('pendente','enviando','ativando','verificando','concluido','falha','inconclusivo') NOT NULL DEFAULT 'pendente',
  `tentativas`      TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `max_tentativas`  TINYINT UNSIGNED NOT NULL DEFAULT 1,
  `falha_origem`    ENUM('repositorio','comunicacao_olt','procedimento_firmware','verificacao') NULL,
  `falha_detalhe`   VARCHAR(500) NULL,
  `dono`            VARCHAR(64)  NULL,
  `heartbeat`       DATETIME     NULL,
  `iniciado_em`     DATETIME     NULL,
  `concluido_em`    DATETIME     NULL,
  `duracao_ms`      INT UNSIGNED NULL,
  `sw_versao_final` VARCHAR(80)  NULL,
  `criado_em`       DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_camp_onu` (`campanha_id`, `onu_id`),
  UNIQUE KEY `uq_onu_ativa` (`onu_ativa`),
  KEY `ix_estado` (`estado`, `heartbeat`),
  CONSTRAINT `fk_zte_job_camp` FOREIGN KEY (`campanha_id`) REFERENCES `tab_zte_campanha` (`id`),
  CONSTRAINT `fk_zte_job_onu`  FOREIGN KEY (`onu_id`)      REFERENCES `tab_zte_onu` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cada transicao de estado do job, com a saida da CLI ja mascarada.
CREATE TABLE IF NOT EXISTS `tab_zte_job_evento` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `job_id`      INT UNSIGNED NOT NULL,
  `de_estado`   VARCHAR(20)  NULL,
  `para_estado` VARCHAR(20)  NOT NULL,
  `op_id`       VARCHAR(64)  NULL,
  `detalhe`     VARCHAR(500) NULL,
  `saida_cli`   MEDIUMTEXT   NULL,
  `criado_em`   DATETIME     NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_job` (`job_id`, `criado_em`),
  KEY `ix_op` (`op_id`),
  CONSTRAINT `fk_zte_jobev_job` FOREIGN KEY (`job_id`) REFERENCES `tab_zte_job` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Estado das atualizacoes na OLT SIMULADA (demonstracao e testes). Nunca usada por OLT real.
CREATE TABLE IF NOT EXISTS `tab_zte_sim_upgrade` (
  `olt_id`          INT UNSIGNED NOT NULL,
  `slot`            TINYINT UNSIGNED NOT NULL,
  `porta`           TINYINT UNSIGNED NOT NULL,
  `onu_num`         SMALLINT UNSIGNED NOT NULL,
  `versao_alvo`     VARCHAR(80)  NOT NULL,
  `versao_anterior` VARCHAR(80)  NULL,
  `falha`           VARCHAR(20)  NULL,
  `iniciado_em`     DATETIME     NOT NULL,
  PRIMARY KEY (`olt_id`, `slot`, `porta`, `onu_num`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 0.8.0: simulacao em 3 passos (update -> activate -> commit), como o procedimento real.
ALTER TABLE `tab_zte_sim_upgrade` ADD COLUMN IF NOT EXISTS `ativado_em` DATETIME NULL AFTER `iniciado_em`;
ALTER TABLE `tab_zte_sim_upgrade` ADD COLUMN IF NOT EXISTS `confirmado` TINYINT(1) NOT NULL DEFAULT 0 AFTER `ativado_em`;

-- 0.8.1: commit assincrono como na OLT real (aceita e grava depois); % da transferencia no job.
ALTER TABLE `tab_zte_sim_upgrade` ADD COLUMN IF NOT EXISTS `confirmado_em` DATETIME NULL AFTER `confirmado`;
ALTER TABLE `tab_zte_job` ADD COLUMN IF NOT EXISTS `progresso` TINYINT UNSIGNED NULL AFTER `heartbeat`;

-- 0.8.2: atualizacao AVULSA pelo inventario (tecnico no local) — campanha de 1 ONU sem regra,
-- com o firmware mais novo compativel, aprovada na hora e sem janela.
ALTER TABLE `tab_zte_campanha` ADD COLUMN IF NOT EXISTS `tipo` ENUM('campanha','avulsa') NOT NULL DEFAULT 'campanha' AFTER `nome`;
ALTER TABLE `tab_zte_campanha` MODIFY `regra_id` INT UNSIGNED NULL;

-- 0.8.3: o modo remote baixa o firmware para a FLASH da OLT antes de enviar a ONU.
-- Ultima leitura do espaco (cache curto: simulacao/aprovacao nao fazem login a toa).
ALTER TABLE `tab_zte_olt` ADD COLUMN IF NOT EXISTS `flash_total` BIGINT UNSIGNED NULL;
ALTER TABLE `tab_zte_olt` ADD COLUMN IF NOT EXISTS `flash_livre` BIGINT UNSIGNED NULL;
ALTER TABLE `tab_zte_olt` ADD COLUMN IF NOT EXISTS `flash_lido_em` DATETIME NULL;
-- 0.8.6: arquivos da pasta "other" (a imagem de ONU baixada fica la ate o aging-time da OLT).
ALTER TABLE `tab_zte_olt` ADD COLUMN IF NOT EXISTS `flash_arquivos` TEXT NULL AFTER `flash_livre`;
-- Resumo da OLT lido ANTES do update (a lista de falhas nao tem data: so conta o que surgir depois).
ALTER TABLE `tab_zte_job` ADD COLUMN IF NOT EXISTS `resumo_antes` TEXT NULL AFTER `progresso`;
ALTER TABLE `tab_zte_sim_upgrade` ADD COLUMN IF NOT EXISTS `arquivo` VARCHAR(64) NULL AFTER `falha`;

-- Batimento do worker (uma linha por papel de worker).
CREATE TABLE IF NOT EXISTS `tab_zte_worker` (
  `nome`         VARCHAR(40)  NOT NULL,
  `pid`          INT UNSIGNED NULL,
  `host`         VARCHAR(120) NULL,
  `iniciado_em`  DATETIME     NULL,
  `heartbeat`    DATETIME     NULL,
  `terminado_em` DATETIME     NULL,
  `resultado`    ENUM('rodando','ok','aviso','erro') NULL,
  `detalhe`      VARCHAR(500) NULL,
  PRIMARY KEY (`nome`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 0.10.0: campanhas RECORRENTES (agenda por dia da semana + janela; cada rodada vira uma campanha
-- comum "rodada", filha da recorrente) e ARQUIVO (some da lista, historico preservado).
ALTER TABLE `tab_zte_campanha` MODIFY `tipo` ENUM('campanha','avulsa','recorrente','rodada') NOT NULL DEFAULT 'campanha';
ALTER TABLE `tab_zte_campanha` ADD COLUMN IF NOT EXISTS `pai_id` INT UNSIGNED NULL AFTER `tipo`;
ALTER TABLE `tab_zte_campanha` ADD COLUMN IF NOT EXISTS `dias_semana` VARCHAR(20) NULL AFTER `janela_fim`;
ALTER TABLE `tab_zte_campanha` ADD COLUMN IF NOT EXISTS `teto_rodada` SMALLINT UNSIGNED NULL AFTER `dias_semana`;
ALTER TABLE `tab_zte_campanha` ADD COLUMN IF NOT EXISTS `aprovacao_assinatura` CHAR(64) NULL AFTER `aprovada_por`;
ALTER TABLE `tab_zte_campanha` ADD COLUMN IF NOT EXISTS `ultima_rodada_data` DATE NULL AFTER `aprovacao_assinatura`;
ALTER TABLE `tab_zte_campanha` ADD COLUMN IF NOT EXISTS `ultima_rodada_resumo` VARCHAR(300) NULL AFTER `ultima_rodada_data`;
ALTER TABLE `tab_zte_campanha` ADD COLUMN IF NOT EXISTS `arquivada` TINYINT(1) NOT NULL DEFAULT 0 AFTER `ultima_rodada_resumo`;
ALTER TABLE `tab_zte_campanha` ADD COLUMN IF NOT EXISTS `arquivada_em` DATETIME NULL AFTER `arquivada`;
ALTER TABLE `tab_zte_campanha` ADD COLUMN IF NOT EXISTS `arquivada_por` VARCHAR(60) NULL AFTER `arquivada_em`;
ALTER TABLE `tab_zte_campanha` ADD INDEX IF NOT EXISTS `ix_pai` (`pai_id`);
-- Falhas de rodadas anteriores ficam fora das proximas ate o operador libera-las (esta data).
ALTER TABLE `tab_zte_campanha` ADD COLUMN IF NOT EXISTS `falhas_liberadas_em` DATETIME NULL AFTER `ultima_rodada_resumo`;
