<?php

declare(strict_types=1);

namespace ChurchToolsPlugin\Tests;

use ChurchToolsPlugin\Address;
use PHPUnit\Framework\TestCase;

/**
 * Die Anschrift der Gemeinde ist die eine Angabe, die das Plugin *ergänzt*
 * statt sie anzuzeigen: Sie steht in strukturierten Daten und in der `.ics`,
 * also genau dort, wo niemand hinsieht. Eine falsch zusammengesetzte Zeile
 * fällt deshalb nur auf, wenn ein Test danach fragt.
 */
final class AddressTest extends TestCase
{
    public function testThePostalLineLeavesOutTheBuildingName(): void
    {
        // Den Namen stellt an dieser Stelle der Raum - „Gemeindehaus, Saal 1,
        // Gemeindehaus" wäre eine Wiederholung.
        $this->assertSame('Hauptstraße 1, 75015 Bretten', Address::postalLine($this->address()));
    }

    /**
     * Dieselbe Regel wie in der Ortszeile eines Termins: Der Ortsteil hängt an
     * der Stadt, weil er für Ortsfremde häufig der Name ist, unter dem sie den
     * Ort überhaupt einordnen.
     */
    public function testTheDistrictHangsOnTheCity(): void
    {
        $address = ['zip' => '75015', 'city' => 'Bretten', 'district' => 'Ruit'];

        $this->assertSame('75015 Bretten-Ruit', Address::cityLine($address));
    }

    public function testADistrictTheCityAlreadyNamesIsNotRepeated(): void
    {
        $address = ['zip' => '75015', 'city' => 'Bretten-Ruit', 'district' => 'Ruit'];

        $this->assertSame('75015 Bretten-Ruit', Address::cityLine($address));
    }

    /**
     * Anders als in der lesbaren Zeile gehört der Ländercode in die
     * strukturierte Anschrift: `addressCountry` erwartet genau das, was
     * ChurchTools liefert.
     */
    public function testTheStructuredAddressCarriesTheCountryCode(): void
    {
        $postal = Address::schemaAddress($this->address());

        $this->assertSame('PostalAddress', $postal['@type']);
        $this->assertSame('Hauptstraße 1', $postal['streetAddress']);
        $this->assertSame('75015', $postal['postalCode']);
        $this->assertSame('Bretten', $postal['addressLocality']);
        $this->assertSame('DE', $postal['addressCountry']);
    }

    /**
     * `addressLocality` ist der Ort, nicht die Zeile: Die PLZ hat ein eigenes
     * Feld und darf nicht zweimal dastehen. Der Ortsteil gehört dagegen dazu,
     * schema.org kennt kein eigenes Feld für ihn.
     */
    public function testTheLocalityHoldsTheDistrictButNotThePostalCode(): void
    {
        $postal = Address::schemaAddress($this->address(['district' => 'Ruit']));

        $this->assertSame('Bretten-Ruit', $postal['addressLocality']);
        $this->assertSame('75015', $postal['postalCode']);
    }

    /**
     * Eine Gemeinde ohne gepflegte Anschrift ist ein gültiger Fall - dann gibt
     * es keine strukturierte Anschrift, statt einer leeren Hülle mit `@type`.
     */
    public function testAnEmptyAddressYieldsNothing(): void
    {
        $this->assertSame([], Address::schemaAddress([]));
        $this->assertSame([], Address::schemaAddress(['country' => 'DE']));
    }

    public function testCoordinatesBecomeGeoCoordinates(): void
    {
        $geo = Address::geo($this->address());

        $this->assertSame(['@type' => 'GeoCoordinates', 'latitude' => '49.0368', 'longitude' => '8.7057'], $geo);
    }

    /**
     * Eine Breite ohne Länge verortet nichts - ein halbes Paar ist kein Ort,
     * sondern eine Linie um die Erde.
     */
    public function testHalfACoordinatePairIsNoPlace(): void
    {
        $this->assertSame([], Address::geo($this->address(['longitude' => ''])));
        $this->assertSame([], Address::geo($this->address(['latitude' => ''])));
    }

    private function address(array $overrides = []): array
    {
        return array_replace([
            'name' => 'Gemeindehaus',
            'street' => 'Hauptstraße 1',
            'zip' => '75015',
            'city' => 'Bretten',
            'district' => '',
            'country' => 'DE',
            'latitude' => '49.0368',
            'longitude' => '8.7057',
        ], $overrides);
    }
}
