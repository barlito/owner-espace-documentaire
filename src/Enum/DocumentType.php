<?php

declare(strict_types=1);

namespace App\Enum;

/**
 * Document nature, grouped by family according to the OWNER document list.
 * Rare documents are merged: the list describes the diversity to support,
 * it does not require a dedicated processing per category.
 */
enum DocumentType: string
{
    case AnnualAccounts = 'annual_accounts';
    case InterimAccounts = 'interim_accounts';
    case TaxNotice = 'tax_notice';
    case AccountsApproval = 'accounts_approval';
    case AccountsFilingCertificate = 'accounts_filing_certificate';

    case Articles = 'articles';
    case Kbis = 'kbis';
    case IdentityDocument = 'identity_document';
    case ProofOfAddress = 'proof_of_address';
    case LegalAct = 'legal_act';

    case Payslip = 'payslip';
    case Dsn = 'dsn';
    case EmploymentContract = 'employment_contract';
    case HealthInsurance = 'health_insurance';
    case CompanyAgreement = 'company_agreement';

    case ChildcareRegulation = 'childcare_regulation';

    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::AnnualAccounts => 'Bilan, comptes annuels ou liasse fiscale',
            self::InterimAccounts => 'Situation comptable',
            self::TaxNotice => 'Avis d\'impôt',
            self::AccountsApproval => 'Approbation des comptes',
            self::AccountsFilingCertificate => 'Certificat de dépôt des comptes',
            self::Articles => 'Statuts',
            self::Kbis => 'Extrait Kbis',
            self::IdentityDocument => 'Pièce d\'identité',
            self::ProofOfAddress => 'Justificatif de domicile ou de siège',
            self::LegalAct => 'Contrat ou acte juridique',
            self::Payslip => 'Bulletin de salaire',
            self::Dsn => 'Déclaration sociale nominative (DSN)',
            self::EmploymentContract => 'Contrat de travail ou avenant',
            self::HealthInsurance => 'Mutuelle, prévoyance ou DUE',
            self::CompanyAgreement => 'Accord d\'entreprise ou règlement intérieur',
            self::ChildcareRegulation => 'Règlement de fonctionnement ou contrat d\'accueil',
            self::Other => 'Autre document',
        };
    }

    public function family(): DocumentFamily
    {
        return match ($this) {
            self::AnnualAccounts, self::InterimAccounts, self::TaxNotice,
            self::AccountsApproval, self::AccountsFilingCertificate => DocumentFamily::Accounting,
            self::Articles, self::Kbis, self::IdentityDocument,
            self::ProofOfAddress, self::LegalAct => DocumentFamily::Legal,
            self::Payslip, self::Dsn, self::EmploymentContract,
            self::HealthInsurance, self::CompanyAgreement => DocumentFamily::Payroll,
            self::ChildcareRegulation => DocumentFamily::Childcare,
            self::Other => DocumentFamily::Other,
        };
    }

    /**
     * Every nature, grouped by family in the order of the families (for selects with option groups).
     *
     * @return list<array{family: DocumentFamily, types: list<DocumentType>}>
     */
    public static function groupedByFamily(): array
    {
        return array_map(
            static fn (DocumentFamily $family): array => [
                'family' => $family,
                'types' => array_values(array_filter(
                    self::cases(),
                    static fn (self $type): bool => $type->family() === $family,
                )),
            ],
            DocumentFamily::cases(),
        );
    }
}
