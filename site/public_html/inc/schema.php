<?php
declare(strict_types=1);

/**
 * Portable schema for MySQL and SQLite. Run via install.php or `php inc/cli.php migrate`.
 */

function schema_statements(string $driver): array
{
    $pk = $driver === 'sqlite' ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $fk = $driver === 'sqlite' ? 'INTEGER' : 'INT UNSIGNED';
    $suffix = $driver === 'sqlite' ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $text = $driver === 'sqlite' ? 'TEXT' : 'MEDIUMTEXT';

    $tables = [
        'users' => "
            id $pk,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(190) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            role VARCHAR(20) NOT NULL DEFAULT 'admin',
            active TINYINT NOT NULL DEFAULT 1,
            last_login_at DATETIME NULL,
            created_at DATETIME NOT NULL",

        'settings' => "
            setting_key VARCHAR(100) NOT NULL PRIMARY KEY,
            setting_value TEXT NULL",

        'rate_limits' => "
            id $pk,
            rate_key VARCHAR(190) NOT NULL,
            hit_at DATETIME NOT NULL",

        'audit_log' => "
            id $pk,
            user_id $fk NULL,
            user_name VARCHAR(120) NULL,
            action VARCHAR(60) NOT NULL,
            entity VARCHAR(60) NOT NULL,
            entity_id VARCHAR(60) NULL,
            details TEXT NULL,
            ip VARCHAR(64) NULL,
            created_at DATETIME NOT NULL",

        'customers' => "
            id $pk,
            name VARCHAR(160) NOT NULL,
            trade_name VARCHAR(160) NULL,
            document VARCHAR(20) NULL,
            email VARCHAR(190) NULL,
            phone VARCHAR(30) NULL,
            segment VARCHAR(60) NULL,
            city VARCHAR(80) NULL,
            state VARCHAR(2) NULL,
            address VARCHAR(255) NULL,
            postal_code VARCHAR(10) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            notes TEXT NULL,
            asaas_customer_id VARCHAR(60) NULL,
            portal_enabled TINYINT NOT NULL DEFAULT 0,
            portal_password_hash VARCHAR(255) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL",

        'projects' => "
            id $pk,
            customer_id $fk NULL,
            name VARCHAR(160) NOT NULL,
            description TEXT NULL,
            project_type VARCHAR(60) NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            priority VARCHAR(20) NOT NULL DEFAULT 'normal',
            start_date DATE NULL,
            due_date DATE NULL,
            budget DECIMAL(14,2) NOT NULL DEFAULT 0,
            manager VARCHAR(120) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL",

        'project_stages' => "
            id $pk,
            project_id $fk NOT NULL,
            name VARCHAR(120) NOT NULL,
            position INT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            start_date DATE NULL,
            due_date DATE NULL,
            completed_at DATETIME NULL,
            notes TEXT NULL",

        'project_tasks' => "
            id $pk,
            project_id $fk NOT NULL,
            stage_id $fk NULL,
            title VARCHAR(200) NOT NULL,
            assignee VARCHAR(120) NULL,
            due_date DATE NULL,
            done TINYINT NOT NULL DEFAULT 0,
            position INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL",

        'leads' => "
            id $pk,
            name VARCHAR(160) NOT NULL,
            email VARCHAR(190) NULL,
            phone VARCHAR(30) NULL,
            company VARCHAR(160) NULL,
            subject VARCHAR(160) NULL,
            message TEXT NULL,
            source VARCHAR(40) NOT NULL DEFAULT 'contact',
            payload TEXT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'new',
            created_at DATETIME NOT NULL",

        'appointments' => "
            id $pk,
            name VARCHAR(160) NOT NULL,
            email VARCHAR(190) NULL,
            phone VARCHAR(30) NULL,
            company VARCHAR(160) NULL,
            topic VARCHAR(160) NULL,
            meeting_type VARCHAR(20) NOT NULL DEFAULT 'online',
            scheduled_at DATETIME NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'scheduled',
            notes TEXT NULL,
            created_at DATETIME NOT NULL",

        'tickets' => "
            id $pk,
            protocol VARCHAR(20) NOT NULL UNIQUE,
            customer_id $fk NULL,
            name VARCHAR(160) NOT NULL,
            email VARCHAR(190) NOT NULL,
            phone VARCHAR(30) NULL,
            subject VARCHAR(200) NOT NULL,
            category VARCHAR(40) NOT NULL DEFAULT 'duvida',
            priority VARCHAR(20) NOT NULL DEFAULT 'normal',
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            sla_due_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL",

        'ticket_messages' => "
            id $pk,
            ticket_id $fk NOT NULL,
            author_type VARCHAR(20) NOT NULL,
            author_name VARCHAR(160) NULL,
            body TEXT NOT NULL,
            created_at DATETIME NOT NULL",

        'posts' => "
            id $pk,
            slug VARCHAR(190) NOT NULL UNIQUE,
            title VARCHAR(200) NOT NULL,
            excerpt VARCHAR(400) NULL,
            content $text NULL,
            category VARCHAR(60) NULL,
            cover_image VARCHAR(255) NULL,
            reading_minutes INT NOT NULL DEFAULT 4,
            published TINYINT NOT NULL DEFAULT 0,
            published_at DATETIME NULL,
            created_at DATETIME NOT NULL",

        'help_articles' => "
            id $pk,
            category VARCHAR(60) NOT NULL,
            question VARCHAR(255) NOT NULL,
            answer TEXT NOT NULL,
            keywords VARCHAR(255) NULL,
            position INT NOT NULL DEFAULT 0,
            views INT NOT NULL DEFAULT 0,
            helpful INT NOT NULL DEFAULT 0,
            published TINYINT NOT NULL DEFAULT 1",

        'categories' => "
            id $pk,
            name VARCHAR(100) NOT NULL,
            entry_type VARCHAR(20) NOT NULL,
            dre_group VARCHAR(30) NOT NULL,
            color VARCHAR(10) NULL",

        'financial_entries' => "
            id $pk,
            entry_type VARCHAR(20) NOT NULL,
            description VARCHAR(255) NOT NULL,
            category_id $fk NULL,
            customer_id $fk NULL,
            project_id $fk NULL,
            supplier VARCHAR(160) NULL,
            amount DECIMAL(14,2) NOT NULL,
            due_date DATE NOT NULL,
            competence_date DATE NULL,
            paid_amount DECIMAL(14,2) NULL,
            paid_at DATE NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            payment_method VARCHAR(30) NULL,
            document_number VARCHAR(60) NULL,
            charge_id $fk NULL,
            bank_transaction_id $fk NULL,
            distribution_id $fk NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL",

        'bank_transactions' => "
            id $pk,
            source VARCHAR(20) NOT NULL DEFAULT 'asaas',
            external_id VARCHAR(80) NULL,
            tx_date DATE NOT NULL,
            description VARCHAR(255) NULL,
            amount DECIMAL(14,2) NOT NULL,
            balance DECIMAL(14,2) NULL,
            tx_type VARCHAR(60) NULL,
            payment_external_id VARCHAR(80) NULL,
            reconciled TINYINT NOT NULL DEFAULT 0,
            entry_id $fk NULL,
            ignored TINYINT NOT NULL DEFAULT 0,
            imported_at DATETIME NOT NULL",

        'charges' => "
            id $pk,
            customer_id $fk NOT NULL,
            project_id $fk NULL,
            entry_id $fk NULL,
            asaas_payment_id VARCHAR(80) NULL,
            billing_type VARCHAR(20) NOT NULL DEFAULT 'UNDEFINED',
            amount DECIMAL(14,2) NOT NULL,
            net_amount DECIMAL(14,2) NULL,
            due_date DATE NOT NULL,
            description VARCHAR(255) NULL,
            status VARCHAR(40) NOT NULL DEFAULT 'PENDING',
            invoice_url VARCHAR(255) NULL,
            bank_slip_url VARCHAR(255) NULL,
            pix_payload TEXT NULL,
            paid_at DATE NULL,
            demo TINYINT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL",

        'partners' => "
            id $pk,
            name VARCHAR(160) NOT NULL,
            document VARCHAR(20) NULL,
            email VARCHAR(190) NULL,
            share_percent DECIMAL(6,2) NOT NULL DEFAULT 0,
            pix_key VARCHAR(160) NULL,
            active TINYINT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL",

        'distributions' => "
            id $pk,
            period_start DATE NOT NULL,
            period_end DATE NOT NULL,
            net_profit DECIMAL(14,2) NOT NULL,
            reserve_percent DECIMAL(6,2) NOT NULL DEFAULT 0,
            distributable DECIMAL(14,2) NOT NULL,
            notes TEXT NULL,
            created_by VARCHAR(120) NULL,
            created_at DATETIME NOT NULL",

        'distribution_items' => "
            id $pk,
            distribution_id $fk NOT NULL,
            partner_id $fk NOT NULL,
            share_percent DECIMAL(6,2) NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            entry_id $fk NULL",

        'nfse_invoices' => "
            id $pk,
            customer_id $fk NULL,
            charge_id $fk NULL,
            entry_id $fk NULL,
            environment VARCHAR(20) NOT NULL,
            dps_serie VARCHAR(5) NOT NULL,
            dps_number INT NOT NULL,
            dps_id VARCHAR(45) NOT NULL,
            toma_document VARCHAR(14) NULL,
            toma_name VARCHAR(300) NULL,
            toma_email VARCHAR(190) NULL,
            toma_phone VARCHAR(30) NULL,
            service_code VARCHAR(6) NOT NULL,
            nbs_code VARCHAR(9) NULL,
            description TEXT NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            iss_rate DECIMAL(6,2) NOT NULL DEFAULT 0,
            iss_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            iss_withheld TINYINT NOT NULL DEFAULT 0,
            competence_date DATE NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            access_key VARCHAR(50) NULL,
            nfse_number VARCHAR(20) NULL,
            xml_dps $text NULL,
            xml_nfse $text NULL,
            xml_cancel $text NULL,
            alerts TEXT NULL,
            error_message TEXT NULL,
            issued_at DATETIME NULL,
            cancel_reason VARCHAR(255) NULL,
            canceled_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL",

        'ai_logs' => "
            id $pk,
            feature VARCHAR(30) NOT NULL,
            prompt_chars INT NOT NULL DEFAULT 0,
            response_chars INT NOT NULL DEFAULT 0,
            ok TINYINT NOT NULL DEFAULT 1,
            error VARCHAR(255) NULL,
            ip VARCHAR(64) NULL,
            created_at DATETIME NOT NULL",

        'email_outbox' => "
            id $pk,
            event VARCHAR(40) NULL,
            to_email VARCHAR(190) NOT NULL,
            to_name VARCHAR(190) NULL,
            reply_to VARCHAR(190) NULL,
            subject VARCHAR(255) NOT NULL,
            html $text NOT NULL,
            text_body $text NULL,
            attachments $text NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            attempts INT NOT NULL DEFAULT 0,
            last_error VARCHAR(500) NULL,
            send_after DATETIME NULL,
            sent_at DATETIME NULL,
            created_at DATETIME NOT NULL",

        'auth_tokens' => "
            id $pk,
            subject_type VARCHAR(20) NOT NULL,
            subject_id $fk NOT NULL,
            purpose VARCHAR(20) NOT NULL,
            selector VARCHAR(32) NOT NULL,
            token_hash VARCHAR(64) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            ip VARCHAR(64) NULL,
            user_agent VARCHAR(255) NULL,
            created_at DATETIME NOT NULL",

        'newsletter' => "
            id $pk,
            email VARCHAR(190) NOT NULL UNIQUE,
            created_at DATETIME NOT NULL",

        'activities' => "
            id $pk,
            entity VARCHAR(20) NOT NULL,
            entity_id $fk NOT NULL,
            kind VARCHAR(20) NOT NULL DEFAULT 'note',
            body TEXT NOT NULL,
            due_at DATETIME NULL,
            done TINYINT NOT NULL DEFAULT 0,
            user_id $fk NULL,
            user_name VARCHAR(120) NULL,
            created_at DATETIME NOT NULL",

        'attachments' => "
            id $pk,
            entity VARCHAR(20) NOT NULL,
            entity_id $fk NOT NULL,
            message_id $fk NULL,
            file_name VARCHAR(255) NOT NULL,
            stored_name VARCHAR(120) NOT NULL,
            mime VARCHAR(120) NULL,
            size_bytes INT NOT NULL DEFAULT 0,
            client_visible TINYINT NOT NULL DEFAULT 1,
            uploaded_by_type VARCHAR(20) NOT NULL DEFAULT 'staff',
            uploaded_by_name VARCHAR(160) NULL,
            created_at DATETIME NOT NULL",

        'canned_responses' => "
            id $pk,
            title VARCHAR(120) NOT NULL,
            body TEXT NOT NULL,
            category VARCHAR(40) NULL,
            uses INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL",

        'saved_reports' => "
            id $pk,
            name VARCHAR(160) NOT NULL,
            description VARCHAR(255) NULL,
            config TEXT NOT NULL,
            pinned TINYINT NOT NULL DEFAULT 0,
            created_by VARCHAR(120) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL",

        'presentations' => "
            id $pk,
            title VARCHAR(200) NOT NULL,
            kind VARCHAR(30) NOT NULL,
            customer_id $fk NULL,
            lead_id $fk NULL,
            project_id $fk NULL,
            theme VARCHAR(20) NOT NULL DEFAULT 'dark',
            slides $text NOT NULL,
            brief TEXT NULL,
            share_token VARCHAR(40) NOT NULL,
            shared TINYINT NOT NULL DEFAULT 0,
            views INT NOT NULL DEFAULT 0,
            last_viewed_at DATETIME NULL,
            ai_generated TINYINT NOT NULL DEFAULT 0,
            created_by VARCHAR(120) NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL",

        'time_entries' => "
            id $pk,
            project_id $fk NOT NULL,
            task_id $fk NULL,
            user_id $fk NULL,
            user_name VARCHAR(120) NULL,
            work_date DATE NOT NULL,
            minutes INT NOT NULL,
            description VARCHAR(255) NULL,
            billable TINYINT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL",

        'project_templates' => "
            id $pk,
            name VARCHAR(160) NOT NULL,
            description VARCHAR(255) NULL,
            project_type VARCHAR(60) NULL,
            stages TEXT NOT NULL,
            created_at DATETIME NOT NULL",

        'budgets' => "
            id $pk,
            category_id $fk NOT NULL,
            month CHAR(7) NOT NULL,
            amount DECIMAL(14,2) NOT NULL DEFAULT 0",

        'price_items' => "
            id $pk,
            code VARCHAR(40) NOT NULL,
            name VARCHAR(160) NOT NULL,
            category VARCHAR(20) NOT NULL,
            billing VARCHAR(20) NOT NULL DEFAULT 'one_time',
            unit VARCHAR(30) NOT NULL DEFAULT 'projeto',
            tier VARCHAR(20) NULL,
            price DECIMAL(14,2) NOT NULL DEFAULT 0,
            market_min DECIMAL(14,2) NULL,
            market_avg DECIMAL(14,2) NULL,
            market_max DECIMAL(14,2) NULL,
            market_source VARCHAR(500) NULL,
            market_updated_at DATE NULL,
            max_discount DECIMAL(6,2) NOT NULL DEFAULT 15,
            description TEXT NULL,
            includes TEXT NULL,
            service_code VARCHAR(10) NULL,
            active TINYINT NOT NULL DEFAULT 1,
            position INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL",

        'quotes' => "
            id $pk,
            number VARCHAR(20) NOT NULL,
            title VARCHAR(200) NOT NULL,
            customer_id $fk NULL,
            lead_id $fk NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            items TEXT NOT NULL,
            setup_discount DECIMAL(6,2) NOT NULL DEFAULT 0,
            monthly_discount DECIMAL(6,2) NOT NULL DEFAULT 0,
            setup_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            monthly_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            first_year_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            installments INT NOT NULL DEFAULT 1,
            contract_months INT NOT NULL DEFAULT 12,
            valid_until DATE NULL,
            start_date DATE NULL,
            notes TEXT NULL,
            presentation_id $fk NULL,
            contract_id $fk NULL,
            project_id $fk NULL,
            created_by VARCHAR(120) NULL,
            accepted_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL",

        'contracts' => "
            id $pk,
            number VARCHAR(20) NOT NULL,
            customer_id $fk NOT NULL,
            quote_id $fk NULL,
            title VARCHAR(200) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'active',
            items TEXT NOT NULL,
            setup_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            monthly_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            support_plan VARCHAR(160) NULL,
            start_date DATE NOT NULL,
            end_date DATE NULL,
            billing_day INT NOT NULL DEFAULT 10,
            next_billing_date DATE NULL,
            auto_charge TINYINT NOT NULL DEFAULT 0,
            billing_type VARCHAR(20) NOT NULL DEFAULT 'UNDEFINED',
            service_code VARCHAR(10) NULL,
            notes TEXT NULL,
            canceled_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL",

        // v8: Integra Fiscal Hub (NFS-e SaaS for customers)
        'fh_plans' => "
            id $pk,
            code VARCHAR(30) NOT NULL UNIQUE,
            name VARCHAR(80) NOT NULL,
            tagline VARCHAR(200) NULL,
            price_monthly DECIMAL(14,2) NOT NULL DEFAULT 0,
            price_yearly DECIMAL(14,2) NOT NULL DEFAULT 0,
            market_avg DECIMAL(14,2) NULL,
            notes_limit INT NOT NULL DEFAULT 30,
            companies_limit INT NOT NULL DEFAULT 1,
            features TEXT NULL,
            flags TEXT NULL,
            highlight TINYINT NOT NULL DEFAULT 0,
            active TINYINT NOT NULL DEFAULT 1,
            position INT NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL",

        'fh_subscriptions' => "
            id $pk,
            customer_id $fk NOT NULL,
            plan_code VARCHAR(30) NOT NULL,
            cycle VARCHAR(10) NOT NULL DEFAULT 'monthly',
            price DECIMAL(14,2) NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            paid_until DATE NULL,
            next_charge_date DATE NULL,
            bonus_notes INT NOT NULL DEFAULT 0,
            billing_type VARCHAR(20) NOT NULL DEFAULT 'UNDEFINED',
            terms_version VARCHAR(20) NULL,
            terms_accepted_at DATETIME NULL,
            terms_ip VARCHAR(45) NULL,
            terms_name VARCHAR(160) NULL,
            started_at DATETIME NULL,
            canceled_at DATETIME NULL,
            cancel_reason VARCHAR(255) NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL",

        'fh_sub_events' => "
            id $pk,
            subscription_id $fk NOT NULL,
            kind VARCHAR(20) NOT NULL,
            description VARCHAR(255) NOT NULL,
            amount DECIMAL(14,2) NULL,
            months INT NULL,
            charge_id $fk NULL,
            user_name VARCHAR(120) NULL,
            created_at DATETIME NOT NULL",

        'fh_emitters' => "
            id $pk,
            customer_id $fk NOT NULL,
            document VARCHAR(14) NOT NULL,
            legal_name VARCHAR(200) NOT NULL,
            trade_name VARCHAR(200) NULL,
            im VARCHAR(20) NULL,
            ie VARCHAR(20) NULL,
            cnae VARCHAR(10) NULL,
            email VARCHAR(190) NULL,
            phone VARCHAR(30) NULL,
            cep VARCHAR(8) NULL,
            street VARCHAR(160) NULL,
            number VARCHAR(20) NULL,
            complement VARCHAR(80) NULL,
            district VARCHAR(80) NULL,
            city VARCHAR(80) NULL,
            uf VARCHAR(2) NULL,
            city_ibge VARCHAR(7) NULL,
            op_simp_nac VARCHAR(1) NOT NULL DEFAULT '3',
            reg_ap_trib_sn VARCHAR(1) NULL,
            reg_esp_trib VARCHAR(1) NOT NULL DEFAULT '0',
            simples_rate DECIMAL(6,2) NOT NULL DEFAULT 0,
            total_tax_pct DECIMAL(6,2) NOT NULL DEFAULT 0,
            provider VARCHAR(20) NOT NULL DEFAULT 'sigiss',
            environment VARCHAR(20) NOT NULL DEFAULT 'homologation',
            sigiss_password TEXT NULL,
            sigiss_crc VARCHAR(20) NULL,
            sigiss_crc_uf VARCHAR(2) NULL,
            cert_pfx $text NULL,
            cert_password TEXT NULL,
            cert_subject VARCHAR(255) NULL,
            cert_valid_to DATE NULL,
            dps_serie VARCHAR(5) NOT NULL DEFAULT '1',
            next_number INT NOT NULL DEFAULT 1,
            iss_rate DECIMAL(6,2) NOT NULL DEFAULT 0,
            pis_rate DECIMAL(6,2) NULL,
            cofins_rate DECIMAL(6,2) NULL,
            csll_rate DECIMAL(6,2) NULL,
            irrf_rate DECIMAL(6,2) NULL,
            inss_rate DECIMAL(6,2) NULL,
            pis_cofins_cst VARCHAR(2) NULL,
            withhold_federal_pj TINYINT NOT NULL DEFAULT 0,
            show_taxes TINYINT NOT NULL DEFAULT 1,
            auto_email TINYINT NOT NULL DEFAULT 1,
            email_message TEXT NULL,
            default_service_id $fk NULL,
            active TINYINT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL",

        'fh_services' => "
            id $pk,
            emitter_id $fk NOT NULL,
            name VARCHAR(160) NOT NULL,
            description TEXT NULL,
            lc116 VARCHAR(5) NULL,
            ctribnac VARCHAR(6) NULL,
            ctribmun VARCHAR(3) NULL,
            sigiss_code VARCHAR(10) NULL,
            cnbs VARCHAR(9) NULL,
            iss_rate DECIMAL(6,2) NULL,
            iss_retention VARCHAR(1) NOT NULL DEFAULT '1',
            trib_issqn VARCHAR(1) NOT NULL DEFAULT '1',
            sigiss_situacao VARCHAR(2) NULL,
            price DECIMAL(14,2) NULL,
            unit VARCHAR(20) NULL,
            pis_rate DECIMAL(6,2) NULL,
            cofins_rate DECIMAL(6,2) NULL,
            csll_rate DECIMAL(6,2) NULL,
            irrf_rate DECIMAL(6,2) NULL,
            inss_rate DECIMAL(6,2) NULL,
            active TINYINT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL",

        'fh_takers' => "
            id $pk,
            emitter_id $fk NOT NULL,
            kind VARCHAR(4) NOT NULL DEFAULT 'pj',
            document VARCHAR(14) NULL,
            name VARCHAR(200) NOT NULL,
            trade_name VARCHAR(200) NULL,
            email VARCHAR(190) NULL,
            phone VARCHAR(30) NULL,
            im VARCHAR(20) NULL,
            ie VARCHAR(20) NULL,
            cep VARCHAR(8) NULL,
            street VARCHAR(160) NULL,
            number VARCHAR(20) NULL,
            complement VARCHAR(80) NULL,
            district VARCHAR(80) NULL,
            city VARCHAR(80) NULL,
            uf VARCHAR(2) NULL,
            city_ibge VARCHAR(7) NULL,
            country VARCHAR(2) NULL,
            foreign_city VARCHAR(80) NULL,
            foreign_region VARCHAR(80) NULL,
            foreign_postal VARCHAR(11) NULL,
            nif VARCHAR(40) NULL,
            notes TEXT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL",

        'fh_invoices' => "
            id $pk,
            emitter_id $fk NOT NULL,
            customer_id $fk NOT NULL,
            provider VARCHAR(20) NOT NULL,
            environment VARCHAR(20) NOT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'draft',
            source VARCHAR(20) NOT NULL DEFAULT 'manual',
            dps_serie VARCHAR(5) NOT NULL,
            dps_number INT NOT NULL,
            dps_id VARCHAR(45) NOT NULL,
            taker_id $fk NULL,
            toma_kind VARCHAR(4) NULL,
            toma_document VARCHAR(40) NULL,
            toma_name VARCHAR(200) NULL,
            toma_email VARCHAR(190) NULL,
            toma_json TEXT NULL,
            service_id $fk NULL,
            service_name VARCHAR(160) NULL,
            lc116 VARCHAR(5) NULL,
            ctribnac VARCHAR(6) NULL,
            ctribmun VARCHAR(3) NULL,
            sigiss_code VARCHAR(10) NULL,
            cnbs VARCHAR(9) NULL,
            description TEXT NOT NULL,
            competence_date DATE NOT NULL,
            amount DECIMAL(14,2) NOT NULL,
            discount_incond DECIMAL(14,2) NOT NULL DEFAULT 0,
            discount_cond DECIMAL(14,2) NOT NULL DEFAULT 0,
            deductions DECIMAL(14,2) NOT NULL DEFAULT 0,
            base DECIMAL(14,2) NOT NULL DEFAULT 0,
            iss_rate DECIMAL(6,2) NOT NULL DEFAULT 0,
            iss_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            iss_retention VARCHAR(1) NOT NULL DEFAULT '1',
            trib_issqn VARCHAR(1) NOT NULL DEFAULT '1',
            sigiss_situacao VARCHAR(2) NULL,
            pis_cofins_cst VARCHAR(2) NULL,
            pis_rate DECIMAL(6,2) NOT NULL DEFAULT 0,
            pis_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            pis_withheld TINYINT NOT NULL DEFAULT 0,
            cofins_rate DECIMAL(6,2) NOT NULL DEFAULT 0,
            cofins_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            cofins_withheld TINYINT NOT NULL DEFAULT 0,
            csll_rate DECIMAL(6,2) NOT NULL DEFAULT 0,
            csll_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            csll_withheld TINYINT NOT NULL DEFAULT 0,
            irrf_rate DECIMAL(6,2) NOT NULL DEFAULT 0,
            irrf_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            irrf_withheld TINYINT NOT NULL DEFAULT 0,
            inss_rate DECIMAL(6,2) NOT NULL DEFAULT 0,
            inss_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            inss_withheld TINYINT NOT NULL DEFAULT 0,
            total_taxes_pct DECIMAL(6,2) NOT NULL DEFAULT 0,
            total_taxes_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            withheld_total DECIMAL(14,2) NOT NULL DEFAULT 0,
            net_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
            extra TEXT NULL,
            nfse_number VARCHAR(20) NULL,
            access_key VARCHAR(60) NULL,
            verification_code VARCHAR(80) NULL,
            print_url VARCHAR(500) NULL,
            xml_dps $text NULL,
            xml_nfse $text NULL,
            xml_cancel $text NULL,
            error_message TEXT NULL,
            alerts TEXT NULL,
            recurring_id $fk NULL,
            issued_at DATETIME NULL,
            canceled_at DATETIME NULL,
            cancel_reason VARCHAR(255) NULL,
            emailed_at DATETIME NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL",

        'fh_recurring' => "
            id $pk,
            emitter_id $fk NOT NULL,
            taker_id $fk NOT NULL,
            service_id $fk NULL,
            amount DECIMAL(14,2) NOT NULL,
            description TEXT NULL,
            day_of_month INT NOT NULL DEFAULT 1,
            next_run DATE NOT NULL,
            last_run DATE NULL,
            end_date DATE NULL,
            active TINYINT NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL",
    ];

    // v9: Integra Fiscal Hub financial module (payables/receivables, cash flow, statements, reconciliation)
    require_once __DIR__ . '/schema_fh_fin.php';
    $fin = fh_schema_fin($pk, $fk, $text);
    $tables += $fin['tables'];

    $statements = [];
    foreach ($tables as $name => $cols) {
        $statements[] = "CREATE TABLE IF NOT EXISTS $name ($cols)$suffix";
    }

    $indexes = [
        'idx_rate_key' => 'rate_limits (rate_key, hit_at)',
        'idx_projects_customer' => 'projects (customer_id)',
        'idx_stages_project' => 'project_stages (project_id, position)',
        'idx_tasks_project' => 'project_tasks (project_id, stage_id)',
        'idx_tickets_status' => 'tickets (status)',
        'idx_ticket_msgs' => 'ticket_messages (ticket_id)',
        'idx_entries_due' => 'financial_entries (entry_type, status, due_date)',
        'idx_entries_paid' => 'financial_entries (paid_at)',
        'idx_bank_ext' => 'bank_transactions (source, external_id)',
        'idx_bank_date' => 'bank_transactions (tx_date)',
        'idx_charges_asaas' => 'charges (asaas_payment_id)',
        'idx_audit_created' => 'audit_log (created_at)',
        'idx_nfse_status' => 'nfse_invoices (status, created_at)',
        'idx_nfse_dps' => 'nfse_invoices (environment, dps_serie, dps_number)',
        'idx_ai_logs' => 'ai_logs (feature, created_at)',
        'idx_outbox_status' => 'email_outbox (status, send_after)',
        'idx_auth_selector' => 'auth_tokens (selector)',
        'idx_auth_subject' => 'auth_tokens (subject_type, subject_id, purpose)',
        'idx_activities_entity' => 'activities (entity, entity_id)',
        'idx_activities_due' => 'activities (done, due_at)',
        'idx_attachments_entity' => 'attachments (entity, entity_id)',
        'idx_time_project' => 'time_entries (project_id, work_date)',
        'idx_budgets_month' => 'budgets (month, category_id)',
        'idx_presentations_token' => 'presentations (share_token)',
        'idx_price_items_cat' => 'price_items (category, position)',
        'idx_quotes_status' => 'quotes (status, created_at)',
        'idx_contracts_billing' => 'contracts (status, next_billing_date)',
        'idx_fh_subs_customer' => 'fh_subscriptions (customer_id, status)',
        'idx_fh_events_sub' => 'fh_sub_events (subscription_id)',
        'idx_fh_emitters_customer' => 'fh_emitters (customer_id)',
        'idx_fh_services_emitter' => 'fh_services (emitter_id)',
        'idx_fh_takers_emitter' => 'fh_takers (emitter_id, document)',
        'idx_fh_inv_emitter' => 'fh_invoices (emitter_id, status, issued_at)',
        'idx_fh_inv_customer' => 'fh_invoices (customer_id, created_at)',
        'idx_fh_inv_dps' => 'fh_invoices (emitter_id, environment, dps_serie, dps_number)',
        'idx_fh_recurring_next' => 'fh_recurring (active, next_run)',
    ] + $fin['indexes'];
    foreach ($indexes as $name => $def) {
        $statements[] = $driver === 'sqlite'
            ? "CREATE INDEX IF NOT EXISTS $name ON $def"
            : "CREATE INDEX $name ON $def";
    }
    return $statements;
}

/** Bump when tables/indexes are added; api/index.php migrates automatically. */
const SCHEMA_VERSION = 11;

/** Columns added after the first release: [table, column, definition]. */
function schema_added_columns(string $driver): array
{
    return [
        ['users', 'google_sub', 'VARCHAR(64) NULL'],
        ['users', 'avatar_url', 'VARCHAR(255) NULL'],
        ['customers', 'google_sub', 'VARCHAR(64) NULL'],
        ['customers', 'email_verified_at', 'DATETIME NULL'],
        ['customers', 'portal_last_login_at', 'DATETIME NULL'],
        ['customers', 'address_number', 'VARCHAR(20) NULL'],
        ['customers', 'district', 'VARCHAR(80) NULL'],
        ['customers', 'city_ibge', 'VARCHAR(7) NULL'],
        ['nfse_invoices', 'provider', "VARCHAR(20) NOT NULL DEFAULT 'nacional'"],
        ['nfse_invoices', 'verification_code', 'VARCHAR(80) NULL'],
        ['nfse_invoices', 'print_url', 'VARCHAR(500) NULL'],
        // v5: flexible statuses, tags, support desk, client approvals
        ['customers', 'tags', 'VARCHAR(255) NULL'],
        ['customers', 'owner', 'VARCHAR(120) NULL'],
        ['projects', 'tags', 'VARCHAR(255) NULL'],
        ['project_stages', 'client_approval', 'VARCHAR(20) NULL'],
        ['project_stages', 'client_feedback', 'TEXT NULL'],
        ['project_stages', 'approval_at', 'DATETIME NULL'],
        ['project_tasks', 'client_visible', 'TINYINT NOT NULL DEFAULT 1'],
        ['leads', 'tags', 'VARCHAR(255) NULL'],
        ['leads', 'estimated_value', 'DECIMAL(14,2) NULL'],
        ['leads', 'next_action_at', 'DATETIME NULL'],
        ['leads', 'lost_reason', 'VARCHAR(255) NULL'],
        ['leads', 'owner', 'VARCHAR(120) NULL'],
        ['tickets', 'assigned_to', 'INT NULL'],
        ['tickets', 'project_id', 'INT NULL'],
        ['tickets', 'tags', 'VARCHAR(255) NULL'],
        ['tickets', 'source', "VARCHAR(20) NOT NULL DEFAULT 'site'"],
        ['tickets', 'first_response_at', 'DATETIME NULL'],
        ['tickets', 'resolved_at', 'DATETIME NULL'],
        ['tickets', 'closed_at', 'DATETIME NULL'],
        ['tickets', 'sla_paused_at', 'DATETIME NULL'],
        ['tickets', 'satisfaction', 'TINYINT NULL'],
        ['tickets', 'satisfaction_comment', 'TEXT NULL'],
        ['ticket_messages', 'internal', 'TINYINT NOT NULL DEFAULT 0'],
        ['ticket_messages', 'kind', "VARCHAR(20) NOT NULL DEFAULT 'message'"],
        ['ticket_messages', 'user_id', 'INT NULL'],
        // v6: time tracking, estimates and project health
        ['projects', 'estimated_hours', 'DECIMAL(10,2) NULL'],
        ['projects', 'hourly_rate', 'DECIMAL(10,2) NULL'],
        ['project_stages', 'estimated_hours', 'DECIMAL(10,2) NULL'],
        ['financial_entries', 'tags', 'VARCHAR(255) NULL'],
        // v7: pricing, quotes, contracts, full NFS-e taxes
        ['financial_entries', 'contract_id', 'INT NULL'],
        ['charges', 'contract_id', 'INT NULL'],
        // v8: Integra Fiscal Hub subscriptions billed through Asaas charges
        ['charges', 'fh_subscription_id', 'INT NULL'],
        ['projects', 'quote_id', 'INT NULL'],
        ['nfse_invoices', 'contract_id', 'INT NULL'],
        ['nfse_invoices', 'discount_amount', 'DECIMAL(14,2) NOT NULL DEFAULT 0'],
        ['nfse_invoices', 'deductions', 'DECIMAL(14,2) NOT NULL DEFAULT 0'],
        ['nfse_invoices', 'pis_cofins_cst', 'VARCHAR(2) NULL'],
        ['nfse_invoices', 'pis_rate', 'DECIMAL(6,2) NOT NULL DEFAULT 0'],
        ['nfse_invoices', 'pis_amount', 'DECIMAL(14,2) NOT NULL DEFAULT 0'],
        ['nfse_invoices', 'pis_withheld', 'TINYINT NOT NULL DEFAULT 0'],
        ['nfse_invoices', 'cofins_rate', 'DECIMAL(6,2) NOT NULL DEFAULT 0'],
        ['nfse_invoices', 'cofins_amount', 'DECIMAL(14,2) NOT NULL DEFAULT 0'],
        ['nfse_invoices', 'cofins_withheld', 'TINYINT NOT NULL DEFAULT 0'],
        ['nfse_invoices', 'csll_rate', 'DECIMAL(6,2) NOT NULL DEFAULT 0'],
        ['nfse_invoices', 'csll_amount', 'DECIMAL(14,2) NOT NULL DEFAULT 0'],
        ['nfse_invoices', 'csll_withheld', 'TINYINT NOT NULL DEFAULT 0'],
        ['nfse_invoices', 'irrf_rate', 'DECIMAL(6,2) NOT NULL DEFAULT 0'],
        ['nfse_invoices', 'irrf_amount', 'DECIMAL(14,2) NOT NULL DEFAULT 0'],
        ['nfse_invoices', 'irrf_withheld', 'TINYINT NOT NULL DEFAULT 0'],
        ['nfse_invoices', 'inss_rate', 'DECIMAL(6,2) NOT NULL DEFAULT 0'],
        ['nfse_invoices', 'inss_amount', 'DECIMAL(14,2) NOT NULL DEFAULT 0'],
        ['nfse_invoices', 'inss_withheld', 'TINYINT NOT NULL DEFAULT 0'],
        ['nfse_invoices', 'total_taxes_pct', 'DECIMAL(6,2) NOT NULL DEFAULT 0'],
        ['nfse_invoices', 'total_taxes_amount', 'DECIMAL(14,2) NOT NULL DEFAULT 0'],
        ['nfse_invoices', 'net_amount', 'DECIMAL(14,2) NULL'],
        // v10: recurring invoices every N months (1 mensal, 2 bimestral, 3 trimestral, 6 semestral, 12 anual)
        ['fh_recurring', 'interval_months', 'INT NOT NULL DEFAULT 1'],
        ['fh_recurring', 'due_day', 'INT NULL'], // payment due day (fills {data_vencimento} and the receivable)
        // v11: entries created by an automatic recurrence (fh_fin_recurring)
        ['fh_fin_entries', 'recurring_id', 'INT NULL'],
    ];
}

/** One-time data updates, each guarded by its own settings flag (safe to run on every migration). */
function schema_data_fixes(): array
{
    $log = [];
    // v9: the "open_finance" plan flag (bank sync through Open Finance) for the paid Fiscal Hub tiers
    if (setting('fh_v9_plan_flags') !== '1') {
        foreach (db_all("SELECT id, code, flags FROM fh_plans WHERE code IN ('profissional', 'business', 'enterprise')") as $p) {
            $flags = json_decode((string)$p['flags'], true) ?: [];
            if (!array_key_exists('open_finance', $flags)) {
                $flags['open_finance'] = true;
                db_exec('UPDATE fh_plans SET flags = ? WHERE id = ?', [json_encode($flags), $p['id']]);
                $log[] = 'fh_plans open_finance ' . $p['code'];
            }
        }
        set_setting('fh_v9_plan_flags', '1');
    }
    // v9: the financial module is part of every Fiscal Hub plan
    if (setting('fh_v9_plan_features') !== '1') {
        foreach (db_all('SELECT id, code, features FROM fh_plans') as $p) {
            $features = json_decode((string)$p['features'], true) ?: [];
            if (!array_filter($features, fn($f) => stripos((string)$f, 'contas a pagar') !== false)) {
                $at = min(2, count($features));
                array_splice($features, $at, 0, ['Financeiro: contas a pagar e a receber, fluxo de caixa e DRE', 'Extrato bancário (OFX, Open Finance e APIs dos bancos) e conciliação']);
                db_exec('UPDATE fh_plans SET features = ? WHERE id = ?', [json_encode($features, JSON_UNESCAPED_UNICODE), $p['id']]);
                $log[] = 'fh_plans features ' . $p['code'];
            }
        }
        set_setting('fh_v9_plan_features', '1');
    }
    // v11: the free plan now includes 5 notes per month (was 15)
    if (setting('fh_v11_free_notes') !== '1') {
        foreach (db_all("SELECT id, tagline, features FROM fh_plans WHERE code = 'gratis'") as $p) {
            $fix = fn($t) => preg_replace('/\b15 notas\b/u', '5 notas', (string)$t);
            $features = array_map($fix, json_decode((string)$p['features'], true) ?: []);
            db_exec('UPDATE fh_plans SET notes_limit = 5, tagline = ?, features = ?, updated_at = ? WHERE id = ?', [$fix($p['tagline']), json_encode($features, JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s'), $p['id']]);
            $log[] = 'fh_plans gratis: 5 notas/mes';
        }
        set_setting('fh_v11_free_notes', '1');
    }
    return $log;
}

function column_exists(string $table, string $column): bool
{
    if (db_driver() === 'sqlite') {
        foreach (db_all("PRAGMA table_info($table)") as $c) if ($c['name'] === $column) return true;
        return false;
    }
    return (bool)db_value('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?', [$table, $column]);
}

function run_migrations(): array
{
    $log = [];
    foreach (schema_statements(db_driver()) as $sql) {
        try {
            db()->exec($sql);
        } catch (PDOException $e) {
            // MySQL has no CREATE INDEX IF NOT EXISTS: ignore "duplicate key name"
            if (strpos($e->getMessage(), 'Duplicate key name') === false) throw $e;
        }
        $log[] = strtok(trim($sql), '(');
    }
    foreach (schema_added_columns(db_driver()) as [$table, $column, $def]) {
        if (!column_exists($table, $column)) {
            db()->exec("ALTER TABLE $table ADD COLUMN $column $def");
            $log[] = "ALTER TABLE $table ADD $column";
            if ($table === 'customers' && $column === 'email_verified_at') {
                // Portal access granted by staff before this feature counts as verified.
                db()->exec("UPDATE customers SET email_verified_at = updated_at WHERE portal_enabled = 1");
            }
        }
    }
    return array_merge($log, schema_data_fixes());
}
