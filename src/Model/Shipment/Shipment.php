<?php

namespace Simplia\Integration\Model\Shipment;

use Simplia\Integration\Model\CurrencyMoney;

class Shipment implements \JsonSerializable {

    /**
     * @var Parcel[]
     */
    private array $parcels;
    private ?string $orderCode;
    private ?string $fullName;
    private ?string $company;
    private ?string $street;
    private ?string $city;
    private ?string $zip;
    private ?string $region;
    private ?string $countryCode;
    private ?string $routing;
    private ?string $branchCode;

    private ?string $phone;
    private ?string $email;

    private ?string $note;
    private ?string $referenceCode;

    private ?string $packageType;

    private ?string $variableSymbol;
    private ?CurrencyMoney $cashOnDelivery;
    private CurrencyMoney $value;

    public function getParcels(): array {
        return $this->parcels;
    }

    public function getOrderCode(): ?string {
        return $this->orderCode;
    }

    public function getFullName(): ?string {
        return $this->fullName;
    }

    public function setFullName(?string $fullName): self {
        $this->fullName = $fullName;

        return $this;
    }

    public function getCompany(): ?string {
        return $this->company;
    }

    public function setCompany(?string $company): self {
        $this->company = $company;

        return $this;
    }

    public function getStreet(): ?string {
        return $this->street;
    }

    public function setStreet(?string $street): self {
        $this->street = $street;

        return $this;
    }

    public function getCity(): ?string {
        return $this->city;
    }

    public function setCity(?string $city): self {
        $this->city = $city;

        return $this;
    }

    public function getZip(): ?string {
        return $this->zip;
    }

    public function setZip(?string $zip): self {
        $this->zip = $zip;

        return $this;
    }

    public function getRegion(): ?string {
        return $this->region;
    }

    public function setRegion(?string $region): self {
        $this->region = $region;

        return $this;
    }

    public function getCountryCode(): ?string {
        return $this->countryCode;
    }

    public function setCountryCode(?string $countryCode): self {
        $this->countryCode = $countryCode;

        return $this;
    }

    public function getRouting(): ?string {
        return $this->routing;
    }

    public function setRouting(?string $routing): self {
        $this->routing = $routing;

        return $this;
    }

    public function getBranchCode(): ?string {
        return $this->branchCode;
    }

    public function setBranchCode(?string $branchCode): self {
        $this->branchCode = $branchCode;

        return $this;
    }

    public function getPhone(): ?string {
        return $this->phone;
    }

    public function setPhone(?string $phone): self {
        $this->phone = $phone;

        return $this;
    }

    public function getEmail(): ?string {
        return $this->email;
    }

    public function setEmail(?string $email): self {
        $this->email = $email;

        return $this;
    }

    public function getNote(): ?string {
        return $this->note;
    }

    public function setNote(?string $note): self {
        $this->note = $note;

        return $this;
    }

    public function getReferenceCode(): ?string {
        return $this->referenceCode;
    }

    public function setReferenceCode(?string $referenceCode): self {
        $this->referenceCode = $referenceCode;

        return $this;
    }

    public function getPackageType(): ?string {
        return $this->packageType;
    }

    public function setPackageType(?string $packageType): self {
        $this->packageType = $packageType;

        return $this;
    }

    public function getVariableSymbol(): ?string {
        return $this->variableSymbol;
    }

    public function setVariableSymbol(?string $variableSymbol): self {
        $this->variableSymbol = $variableSymbol;

        return $this;
    }

    public function getCashOnDelivery(): ?CurrencyMoney {
        return $this->cashOnDelivery;
    }

    public function setCashOnDelivery(?CurrencyMoney $cashOnDelivery): self {
        $this->cashOnDelivery = $cashOnDelivery;

        return $this;
    }

    public function getValue(): CurrencyMoney {
        return $this->value;
    }

    public function setValue(CurrencyMoney $value): self {
        $this->value = $value;

        return $this;
    }

    public static function fromJson(array $data): self {
        $shipment = new self();
        $shipment->parcels = array_map(static fn(array $parcel) => Parcel::fromJson($parcel), $data['parcels']);
        $shipment->orderCode = $data['orderCode'];
        $shipment->fullName = $data['fullName'];
        $shipment->company = $data['company'];
        $shipment->street = $data['street'];
        $shipment->city = $data['city'];
        $shipment->zip = $data['zip'];
        $shipment->region = $data['region'];
        $shipment->countryCode = $data['countryCode'];
        $shipment->routing = $data['routing'];
        $shipment->branchCode = $data['branchCode'];
        $shipment->phone = $data['phone'];
        $shipment->email = $data['email'];
        $shipment->note = $data['note'];
        $shipment->referenceCode = $data['referenceCode'];
        $shipment->packageType = $data['packageType'];
        $shipment->variableSymbol = $data['variableSymbol'];
        $shipment->cashOnDelivery = $data['cashOnDelivery'] ? CurrencyMoney::fromJson($data['cashOnDelivery']) : null;
        $shipment->value = CurrencyMoney::fromJson($data['value']);

        return $shipment;
    }

    public function jsonSerialize(): array {
        return [
            'parcels' => $this->parcels,
            'orderCode' => $this->orderCode,
            'fullName' => $this->fullName,
            'company' => $this->company,
            'street' => $this->street,
            'city' => $this->city,
            'zip' => $this->zip,
            'region' => $this->region,
            'countryCode' => $this->countryCode,
            'routing' => $this->routing,
            'branchCode' => $this->branchCode,
            'phone' => $this->phone,
            'email' => $this->email,
            'note' => $this->note,
            'referenceCode' => $this->referenceCode,
            'packageType' => $this->packageType,
            'variableSymbol' => $this->variableSymbol,
            'cashOnDelivery' => $this->cashOnDelivery,
            'value' => $this->value,
        ];
    }
}
