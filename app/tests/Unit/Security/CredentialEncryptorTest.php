<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\CredentialEncryptor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `CredentialEncryptor` — szyfrowanie poświadczeń IMAP at-rest (etap 1.4).
 *
 * Kontrakt jest krótki, ale konsekwencje błędu są nieodwracalne w obie strony: przeciek oznacza
 * oddanie haseł do cudzych skrzynek, a zbyt „sprytna" zmiana formatu szyfrogramu — utratę dostępu
 * do wszystkich zapisanych kont (starych danych nie da się odczytać, a plaintextu nigdzie nie ma).
 *
 * Dwa przypadki pilnują własności, których nie widać w kodzie na pierwszy rzut oka:
 * `testDwaSzyfrowaniaTegoSamegoTekstuDajaRozneSzyfrogramy()` (nonce losowany per zapis — bez tego
 * po samej bazie widać, które konta mają identyczne hasło) i `testUszkodzonySzyfrogramLeciWyjatkiem()`
 * (secretbox jest UWIERZYTELNIONY, więc podmiana bajtu w bazie ma wybuchnąć, a nie oddać śmieci).
 */
class CredentialEncryptorTest extends TestCase {
    /** Klucz testowy: 32 bajty w zapisie hex, jak `MAIL_CRYPTO_KEY`. */
    private const KEY = '2b4a1cf3ad9e2c7f8b5d0e6a3f1c9d8e7b2a5c4f0d3e6b9a8c1f4d7e0a3b6c9d';
    private const OTHER_KEY = 'ffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffffff';

    /**
     * @param string $plaintext Wartość do zaszyfrowania, np. "Tajne123!"
     */
    #[DataProvider('poswiadczenia')]
    public function testRoundTripOddajeDokladnieToSamo(string $plaintext): void {
        $encryptor = new CredentialEncryptor(self::KEY);

        $this->assertSame($plaintext, $encryptor->decrypt($encryptor->encrypt($plaintext)));
    }

    /**
     * @return iterable<string, array{string}> Wartości, które muszą przeżyć round-trip
     */
    public static function poswiadczenia(): iterable {
        yield 'zwykłe hasło'      => ['Tajne123!'];
        yield 'polskie znaki'     => ['zażółć-gęślą-jaźń'];
        yield 'pusty string'      => [''];
        yield 'bajty binarne'     => ["\x00\xFF\xC3\x28 refresh-token"];
        yield 'długi token OAuth' => [str_repeat('1//0dXwT3f_', 100)];
    }

    /**
     * Nonce jest losowany przy KAŻDYM zapisie. Gdyby był stały, identyczne hasła dawałyby
     * identyczne szyfrogramy — czyli po samym `SELECT secret` widać by było, które konta
     * współdzielą hasło, bez łamania czegokolwiek.
     */
    public function testDwaSzyfrowaniaTegoSamegoTekstuDajaRozneSzyfrogramy(): void {
        $encryptor = new CredentialEncryptor(self::KEY);

        $pierwszy = $encryptor->encrypt('Tajne123!');
        $drugi    = $encryptor->encrypt('Tajne123!');

        $this->assertNotSame($pierwszy, $drugi);
        $this->assertSame('Tajne123!', $encryptor->decrypt($pierwszy));
        $this->assertSame('Tajne123!', $encryptor->decrypt($drugi));
    }

    /** Szyfrogram nie może zdradzać jawnej wartości ani w całości, ani fragmentem. */
    public function testSzyfrogramNieZawieraJawnegoTekstu(): void {
        $encryptor = new CredentialEncryptor(self::KEY);

        $szyfrogram = $encryptor->encrypt('Tajne123!');

        $this->assertStringNotContainsString('Tajne123!', $szyfrogram);
        $this->assertStringNotContainsString('Tajne123!', (string) base64_decode($szyfrogram, true));
    }

    /**
     * Rotacja `MAIL_CRYPTO_KEY` bez re-encryptu istniejących danych ma wybuchnąć głośno.
     * Cicha porażka byłaby gorsza: aplikacja próbowałaby zalogować się do IMAP-a śmieciami.
     */
    public function testZlyKluczLeciWyjatkiem(): void {
        $szyfrogram = (new CredentialEncryptor(self::KEY))->encrypt('Tajne123!');

        $this->expectException(\RuntimeException::class);

        (new CredentialEncryptor(self::OTHER_KEY))->decrypt($szyfrogram);
    }

    /**
     * `secretbox` to szyfrowanie UWIERZYTELNIONE (Poly1305) — podmiana choćby jednego bajtu
     * w bazie musi zostać wykryta, zamiast po cichu odszyfrować się na inną wartość.
     */
    public function testUszkodzonySzyfrogramLeciWyjatkiem(): void {
        $encryptor  = new CredentialEncryptor(self::KEY);
        $surowy     = (string) base64_decode($encryptor->encrypt('Tajne123!'), true);
        $surowy[30] = $surowy[30] === 'a' ? 'b' : 'a';

        $this->expectException(\RuntimeException::class);

        $encryptor->decrypt(base64_encode($surowy));
    }

    /**
     * Wejście, które w ogóle nie jest naszym szyfrogramem (obcy wpis, ucięta kolumna, wartość
     * wklejona ręcznie) — też wyjątek, nigdy „prawie działa".
     *
     * @param string $stored Zawartość kolumny, np. "nie-base64!!!"
     */
    #[DataProvider('niepoprawneSzyfrogramy')]
    public function testNiepoprawneWejscieLeciWyjatkiem(string $stored): void {
        $this->expectException(\RuntimeException::class);

        (new CredentialEncryptor(self::KEY))->decrypt($stored);
    }

    /**
     * @return iterable<string, array{string}> Zawartości kolumny, które nie są szyfrogramem
     */
    public static function niepoprawneSzyfrogramy(): iterable {
        yield 'nie base64'        => ['nie-base64!!!'];
        yield 'pusta wartość'     => [''];
        yield 'za krótkie dane'   => [base64_encode('krótkie')];
        yield 'sam plaintext'     => [base64_encode('Tajne123!')];
    }

    /**
     * Zły `MAIL_CRYPTO_KEY` ma wywalić się przy BUDOWIE usługi, a nie przy pierwszym zapisie
     * konta — inaczej błąd konfiguracji wyszedłby dopiero w panelu admina.
     *
     * @param string $key Wartość `MAIL_CRYPTO_KEY`, np. "" albo "za-krótki"
     */
    #[DataProvider('bledneKlucze')]
    public function testBlednyKluczOdrzuconyPrzyKonstrukcji(string $key): void {
        $this->expectException(\InvalidArgumentException::class);

        new CredentialEncryptor($key);
    }

    /**
     * @return iterable<string, array{string}> Wartości, które nie są 32-bajtowym kluczem hex
     */
    public static function bledneKlucze(): iterable {
        yield 'pusty'          => [''];
        yield 'za krótki hex'  => ['abcdef'];
        yield 'nie hex'        => [str_repeat('z', 64)];
        yield 'za długi hex'   => [str_repeat('a', 128)];
    }
}
