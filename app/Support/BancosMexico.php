<?php

namespace App\Support;

/**
 * Bancos e instituciones de México que pueden recibir una transferencia SPEI.
 *
 * La clave es la de tres dígitos con que empieza la CLABE (catálogo de
 * participantes SPEI de Banxico, listado CEP, consultado en noviembre de 2026).
 * Se dejaron fuera las claves que no son una cuenta para el público: Banxico,
 * CLS, Indeval, CoDi Valida, el fondo de FIRA, Hipotecaria Federal, Nafin,
 * Bancomext y Banobras.
 *
 * Hey Banco y Banregio tienen claves distintas (167 y 058). Una CLABE de Hey
 * Banco abierta antes de 2023 puede empezar con 058: es solo una sugerencia
 * y la distribuidora siempre puede cambiar el banco.
 */
class BancosMexico
{
    /** @var array<string, string> clave de la CLABE => nombre que se muestra y se guarda */
    private const BANCOS = [
        '002' => 'Banamex',
        '012' => 'BBVA',
        '014' => 'Santander',
        '019' => 'Banjercito',
        '021' => 'HSBC',
        '030' => 'Bajío',
        '036' => 'Inbursa',
        '042' => 'Mifel',
        '044' => 'Scotiabank',
        '058' => 'Banregio',
        '059' => 'Invex',
        '060' => 'Bansi',
        '062' => 'Afirme',
        '072' => 'Banorte',
        '106' => 'Bank of America',
        '108' => 'MUFG Bank',
        '110' => 'JP Morgan',
        '112' => 'Monex',
        '113' => 'Ve por Más',
        '124' => 'Citi México',
        '127' => 'Banco Azteca',
        '128' => 'Kapital',
        '129' => 'Barclays',
        '130' => 'Compartamos',
        '132' => 'Multiva',
        '133' => 'Actinver',
        '136' => 'Intercam',
        '137' => 'BanCoppel',
        '138' => 'Ualá',
        '140' => 'Consubanco',
        '141' => 'Volkswagen Bank',
        '145' => 'BBase',
        '147' => 'Bankaool',
        '148' => 'Pagatodo',
        '150' => 'Inmobiliario Mexicano',
        '151' => 'Fundación Dondé',
        '152' => 'Bancrea',
        '154' => 'Banco Covalto',
        '155' => 'ICBC',
        '156' => 'Sabadell',
        '157' => 'Shinhan',
        '158' => 'Mizuho',
        '159' => 'Bank of China',
        '160' => 'Banco S3',
        '166' => 'Banco del Bienestar',
        '167' => 'Hey Banco',
        '170' => 'Revolut',
        '600' => 'Monex Casa de Bolsa',
        '601' => 'GBM',
        '602' => 'Masari',
        '605' => 'Value',
        '616' => 'Finamex',
        '617' => 'Valmex',
        '620' => 'Profuturo',
        '631' => 'TRF',
        '634' => 'Fincomún',
        '638' => 'Nu México',
        '646' => 'STP',
        '652' => 'Credicapital',
        '653' => 'Kuspit',
        '656' => 'Unagra',
        '659' => 'ASP Integra OPC',
        '660' => 'Altor',
        '661' => 'Klar',
        '670' => 'Libertad',
        '677' => 'Caja Popular Mexicana',
        '680' => 'Caja Cristóbal Colón',
        '683' => 'Caja de Ahorro de los Telefonistas',
        '684' => 'Transfer',
        '688' => 'Crediclub',
        '699' => 'Fondeadora',
        '703' => 'Tesored',
        '706' => 'Arcus',
        '710' => 'Nvio',
        '714' => 'PPBalanceMX',
        '715' => 'Cashi Cuenta',
        '720' => 'MexPago',
        '721' => 'Albo',
        '722' => 'Mercado Pago W',
        '723' => 'Cuenca',
        '725' => 'Coopdesarrollo',
        '727' => 'Transfer Direct',
        '728' => 'Spin by OXXO',
        '729' => 'Dep y Pag Dig',
        '730' => 'Clip',
        '732' => 'Peibo',
        '734' => 'Finco Pay',
        '738' => 'Fintoc',
    ];

    /**
     * Nombre => clave, en orden alfabético sin distinguir acentos.
     *
     * @return array<string, string>
     */
    public static function todos(): array
    {
        $porNombre = array_flip(self::BANCOS);

        uksort($porNombre, fn (string $a, string $b) => strcmp(self::ordenable($a), self::ordenable($b)));

        return array_map(fn ($clave) => str_pad((string) $clave, 3, '0', STR_PAD_LEFT), $porNombre);
    }

    /** @return list<string> */
    public static function nombres(): array
    {
        return array_keys(self::todos());
    }

    /**
     * Lo que recibe el select del navegador.
     *
     * @return list<array{nombre: string, clave: string}>
     */
    public static function paraSelect(): array
    {
        $lista = [];

        foreach (self::todos() as $nombre => $clave) {
            // PHP vuelve entero un índice como '141': la clave siempre sale como texto de tres dígitos.
            $lista[] = ['nombre' => $nombre, 'clave' => str_pad((string) $clave, 3, '0', STR_PAD_LEFT)];
        }

        return $lista;
    }

    public static function existe(string $nombre): bool
    {
        return in_array($nombre, self::BANCOS, true);
    }

    public static function nombrePorClave(string $clave): ?string
    {
        return self::BANCOS[$clave] ?? null; // "141" y 141 son la misma llave en PHP
    }

    /** El banco que dicen los tres primeros dígitos de la CLABE, o null. */
    public static function detectarPorClabe(string $clabe): ?string
    {
        $clabe = Clabe::normalizar($clabe);

        if (! preg_match('/^\d{3}/', $clabe)) {
            return null;
        }

        return self::nombrePorClave(substr($clabe, 0, 3));
    }

    private static function ordenable(string $texto): string
    {
        $sinAcentos = strtr(mb_strtolower($texto), [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        ]);

        return $sinAcentos;
    }
}