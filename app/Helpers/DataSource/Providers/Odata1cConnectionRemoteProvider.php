<?php

namespace App\Helpers\DataSource\Providers;

use Illuminate\Support\Facades\Http;
use RuntimeException;
use SimpleXMLElement;

class Odata1cConnectionRemoteProvider
{
    public string $baseUrl; // напр. http://server/base/odata/standard.odata/
    public string $username;
    public ?string $password;

    private ?SimpleXMLElement $metadataCache = null;

    public function __construct(string $baseUrl, string $username, ?string $password)
    {
        $this->baseUrl = rtrim($baseUrl, '/').'/';
        $this->username = $username;
        $this->password = $password;
    }

    private function http()
    {
        return Http::withBasicAuth($this->username, $this->password)
            ->withHeaders(['Accept' => 'application/json']);
    }

    private function metadata(): SimpleXMLElement
    {
        if ($this->metadataCache) {
            return $this->metadataCache;
        }

        $response = $this->http()->get($this->baseUrl.'$metadata');

        if (!$response->successful()) {
            throw new RuntimeException('Не удалось получить $metadata 1С: '.$response->status());
        }

        $xml = new SimpleXMLElement($response->body());
        $xml->registerXPathNamespace('edm', 'http://schemas.microsoft.com/ado/2008/09/edm');

        return $this->metadataCache = $xml;
    }

    public function showTables(): array
    {
        $xml = $this->metadata();
        $xml->registerXPathNamespace('edmx', 'http://schemas.microsoft.com/ado/2007/06/edmx');
        $xml->registerXPathNamespace('edm', 'http://schemas.microsoft.com/ado/2008/09/edm');

        $entitySets = $xml->xpath('//edm:EntityContainer/edm:EntitySet');

        return collect($entitySets)
            ->map(fn ($set) => (string) $set['Name'])
            ->filter()
            ->values()
            ->toArray();
    }

    public function showColumns(string $tableName): array
    {
        $xml = $this->metadata();
        $xml->registerXPathNamespace('edm', 'http://schemas.microsoft.com/ado/2008/09/edm');

        $entitySet = $xml->xpath("//edm:EntityContainer/edm:EntitySet[@Name='{$tableName}']");
        if (empty($entitySet)) {
            return [];
        }

        $entityTypeFull = (string) $entitySet[0]['EntityType']; // напр. StandardODATA.Catalog_Номенклатура
        $entityTypeName = collect(explode('.', $entityTypeFull))->last();

        $entityType = $xml->xpath("//edm:EntityType[@Name='{$entityTypeName}']");
        if (empty($entityType)) {
            return [];
        }

        $columns = [];
        foreach ($entityType[0]->Property as $property) {
            $name = (string) $property['Name'];
            if (!$name) {
                continue;
            }

            $columns[] = [
                'column_name' => $name,
                'type' => (string) $property['Type'],
                'nullable' => ((string) $property['Nullable']) === 'false' ? 'NO' : 'YES',
                'key' => str_contains($name, 'Ref_Key') || $name === 'Ref_Key' ? 'PRI' : '',
                'default' => null,
            ];
        }

        return $columns;
    }

    /**
     * Для 1С $query — это НЕ SQL, а OData resource path с параметрами, например:
     * "Catalog_Номенклатура?$select=Ссылка,Наименование&$filter=Артикул ne ''&$top=100"
     * $bindings не используются напрямую (OData не поддерживает позиционные параметры,
     * подстановка значений должна быть сделана заранее в $filter).
     */
    public function query(string $query, array $bindings = [])
    {
        $separator = str_contains($query, '?') ? '&' : '?';
        $url = $this->baseUrl.$query.$separator.'$format=json';

        $response = $this->http()->get($url);

        if (!$response->successful()) {
            throw new RuntimeException('Ошибка запроса к 1С OData: '.$response->status().' '.$response->body());
        }

        return $response->json('value') ?? [];
    }

    public function check(): array
    {
        try {
            $this->metadata();

            return [
                'success' => true,
                'message' => 'Подключение успешно',
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }
}
