<?php

namespace App\DTO;

use App\Enums\Accounting\DocumentType;
use App\Enums\Setting\Font;
use App\Models\Accounting\Document;
use App\Models\Setting\DocumentDefault;
use App\Utilities\Currency\CurrencyAccessor;
use App\Utilities\Currency\CurrencyConverter;
use Filament\FontProviders\BunnyFontProvider;
use Illuminate\Contracts\Support\Htmlable;

readonly class DocumentDTO
{
    /**
     * @param  LineItemDTO[]  $lineItems
     */
    public function __construct(
        public ?string $header,
        public ?string $subheader,
        public ?string $footer,
        public ?string $terms,
        public ?string $logo,
        public string $number,
        public ?string $referenceNumber,
        public ?string $date,
        public ?string $dueDate,
        public string $currencyCode,
        public ?string $subtotal,
        public ?string $discount,
        public ?string $profit,
        public ?string $tax,
        public string $total,
        public ?string $amountDue,
        public CompanyDTO $company,
        public ?ClientDTO $client,
        public iterable $lineItems,
        public DocumentLabelDTO $label,
        public DocumentColumnLabelDTO $columnLabel,
        public string $accentColor = '#000000',
        public bool $showLogo = true,
        public Font $font = Font::Inter,
        public ?string $email,
        public ?string $terbilang,
        public ?string $dpp,
        public ?string $project,
        public ?string $bankName,
        public ?string $accountName,
        public ?string $accountNumber,
        public ?string $beneficiaryName,
        public ?string $beneficiaryTitle,
        public ?string $contactName,
    ) {}

    public static function fromModel(Document $document): self
    {
        /** @var DocumentDefault $settings */
        $settings = $document->company->documentDefaults()
            ->type($document::documentType())
            ->first() ?? $document->company->defaultInvoice;

        $currencyCode = $document->currency_code ?? CurrencyAccessor::getDefaultCurrency();

        $discount = $document->discount_total > 0
            ? self::formatToMoney($document->discount_total, $currencyCode)
            : null;

        $tax = $document->tax_total > 0
            ? self::formatToMoney($document->tax_total, $currencyCode)
            : null;

        $profit = $document->profit_total > 0
            ? self::formatToMoney($document->profit_total, $currencyCode)
            : null;

        $subtotal = ($discount || $tax)
            ? self::formatToMoney($document->subtotal, $currencyCode)
            : null;

        $amountDue = $document::documentType() !== DocumentType::Estimate ?
            self::formatToMoney($document->amountDue(), $currencyCode) :
            null;

        $projectName = $document->project->name;
        $contactName = $document->client?->primaryContact?->first_name . ' ' . $document->client?->primaryContact?->last_name;
        $isEmptyContactName = trim($contactName) === '';

        return new self(
            header: $settings->header,
            subheader: $settings->subheader,
            footer: $document->footer,
            terms: $document->terms,
            logo: $settings->logo_url,
            number: $document->documentNumber(),
            referenceNumber: $document->referenceNumber(),
            date: $document->documentDate(),
            dueDate: $document->dueDate(),
            currencyCode: $currencyCode,
            subtotal: $subtotal,
            discount: $discount,
            tax: $tax,
            profit: $profit,
            total: self::formatToMoney($document->total, $currencyCode),
            amountDue: $amountDue,
            company: CompanyDTO::fromModel($document->company),
            client: $document->client ? ClientDTO::fromModel($document->client) : null,
            lineItems: $document->lineItems->map(fn($item) => LineItemDTO::fromModel($item)),
            label: $document::documentType()->getLabels(),
            columnLabel: DocumentColumnLabelDTO::fromModel($settings),
            accentColor: $settings->accent_color ?? '#000000',
            showLogo: $settings->show_logo ?? false,
            font: $settings->font ?? Font::Inter,
            email: $document->company->profile->email,
            terbilang: self::generateTerbilang($amountDue),
            dpp: self::generateDpp($document->subtotal),
            project: $projectName,
            bankName: $settings->bank_name,
            accountName: $settings->account_name,
            accountNumber: $settings->account_number,
            beneficiaryName: $settings->beneficiary_name,
            beneficiaryTitle: $settings->beneficiary_title,
            contactName: $isEmptyContactName ? '-' : $contactName,
        );
    }

    protected static function sanitizeRupiah($nilai)
    {
        $nilai = str_replace(['Rp', 'rp', ' '], '', $nilai);
        $nilai = str_replace(',00', '', $nilai);
        $nilai = str_replace('.', '', $nilai);
        return (float)$nilai;
    }

    protected static function generateTerbilang($rupiah)
    {
        $nilai = self::sanitizeRupiah($rupiah);

        $angka = ['', 'Satu', 'Dua', 'Tiga', 'Empat', 'Lima', 'Enam', 'Tujuh', 'Delapan', 'Sembilan', 'Sepuluh', 'Sebelas'];

        $nilai = abs($nilai);

        if ($nilai < 12) {
            return " " . $angka[$nilai];
        } elseif ($nilai < 20) {
            return self::generateTerbilang($nilai - 10) . " Belas";
        } elseif ($nilai < 100) {
            return self::generateTerbilang(intval($nilai / 10)) . " Puluh" . self::generateTerbilang($nilai % 10);
        } elseif ($nilai < 200) {
            return " Seratus" . self::generateTerbilang($nilai - 100);
        } elseif ($nilai < 1000) {
            return self::generateTerbilang(intval($nilai / 100)) . " Ratus" . self::generateTerbilang($nilai % 100);
        } elseif ($nilai < 2000) {
            return " Seribu" . self::generateTerbilang($nilai - 1000);
        } elseif ($nilai < 1000000) {
            return self::generateTerbilang(intval($nilai / 1000)) . " Ribu" . self::generateTerbilang($nilai % 1000);
        } elseif ($nilai < 1000000000) {
            return self::generateTerbilang(intval($nilai / 1000000)) . " Juta" . self::generateTerbilang($nilai % 1000000);
        } elseif ($nilai < 1000000000000) {
            return self::generateTerbilang(intval($nilai / 1000000000)) . " Miliar" . self::generateTerbilang($nilai % 1000000000);
        } else {
            return self::generateTerbilang(intval($nilai / 1000000000000)) . " Triliun" . self::generateTerbilang($nilai % 1000000000000);
        }
        return "";
    }

    protected static function generateDpp($nilai)
    {
        $dpp = round($nilai * (11 / 12));
        $money = money($dpp, 'IDR');
        return $money->format();
    }

    protected static function formatToMoney(int $value, ?string $currencyCode): string
    {
        return CurrencyConverter::formatCentsToMoney($value, $currencyCode);
    }

    public function getFontHtml(): Htmlable
    {
        return app(BunnyFontProvider::class)->getHtml($this->font->getLabel());
    }
}
