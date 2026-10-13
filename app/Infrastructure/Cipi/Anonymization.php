<?php

namespace App\Infrastructure\Cipi;

/**
 * The Cipi agent's anonymization.json, as rows a page can edit and back: a rule per column
 * (fakeEmail, mask:3,2, {type, unique, consistent}…), the tables emptied, the options. It lives
 * on the server at ~/.db/anonymization.json, outside the repository.
 *
 * @see https://cipi.sh/docs/agent#agent-anonymizer
 *
 * @phpstan-type Rule array{table: string, column: string, rule: string, unique: bool, consistent: bool}
 */
final class Anonymization
{
    public const PATH = '.db/anonymization.json';

    public const SCHEMA = 'https://cipi.sh/schema/anonymization.schema.json';

    /** The transformations of the agent, by group. */
    public const TRANSFORMATIONS = [
        'People' => ['fakeName', 'fakeFirstName', 'fakeLastName', 'fakeUserName', 'fakeEmail', 'fakeSafeEmail', 'fakePhoneNumber'],
        'Company' => ['fakeCompany', 'fakeJobTitle'],
        'Address' => ['fakeAddress', 'fakeStreetAddress', 'fakeCity', 'fakeState', 'fakePostcode', 'fakeCountry', 'fakeCountryCode', 'fakeLatitude', 'fakeLongitude'],
        'Dates and text' => ['fakeDate', 'fakeDateTime', 'fakeParagraph', 'fakeSentence', 'fakeText', 'fakeWord'],
        'Web and network' => ['fakeUrl', 'fakeDomain', 'fakeIpv4', 'fakeIpv6', 'fakeMacAddress', 'fakeUserAgent'],
        'Money and ids' => ['fakeIban', 'fakeCreditCardNumber', 'fakeUuid', 'fakeToken', 'fakeIdentifier', 'fakeNumber', 'fakeBoolean'],
        'Patterns' => ['numerify:###', 'lexify:????', 'bothify:##??', 'mask:2,2', 'hash', 'fixed:value'],
        'Plain' => ['password', 'null', 'empty', 'redact', 'keep'],
    ];

    public const TRUNCATE = ['sessions', 'jobs', 'failed_jobs', 'job_batches', 'cache', 'cache_locks', 'password_reset_tokens', 'personal_access_tokens'];

    /** @var array{faker_locale: string, password: string, consistent: bool, gzip: bool} */
    public const OPTIONS = ['faker_locale' => 'en_US', 'password' => 'password', 'consistent' => true, 'gzip' => false];

    /**
     * The file as rows, the tables emptied and the options; something unreadable is an empty file.
     *
     * @return array{rules: list<Rule>, truncate: list<string>, options: array<string, mixed>}
     */
    public static function parse(mixed $config): array
    {
        if (is_string($config)) {
            $config = json_decode($config, true);
        }

        $config = is_array($config) ? $config : [];
        $rules = [];

        foreach ((array) ($config['transformations'] ?? []) as $table => $columns) {
            foreach ((array) $columns as $column => $rule) {
                $rules[] = [
                    'table' => (string) $table,
                    'column' => (string) $column,
                    'rule' => is_array($rule) ? (string) ($rule['type'] ?? 'keep') : (string) $rule,
                    'unique' => is_array($rule) && (bool) ($rule['unique'] ?? false),
                    'consistent' => is_array($rule) && (bool) ($rule['consistent'] ?? false),
                ];
            }
        }

        return [
            'rules' => $rules,
            'truncate' => array_values(array_map('strval', (array) ($config['truncate'] ?? []))),
            'options' => [...self::OPTIONS, ...(array) ($config['options'] ?? [])],
        ];
    }

    /**
     * The file from the rows: a plain rule when it has no flags, {type, unique, consistent} when it has.
     *
     * @param  list<array{table?: mixed, column?: mixed, rule?: mixed, unique?: mixed, consistent?: mixed}>  $rules
     * @param  list<string>  $truncate
     * @param  array<string, mixed>  $options
     */
    public static function build(array $rules, array $truncate, array $options): string
    {
        $transformations = [];

        foreach ($rules as $row) {
            $table = trim((string) ($row['table'] ?? ''));
            $column = trim((string) ($row['column'] ?? ''));
            $rule = trim((string) ($row['rule'] ?? ''));

            if ($table === '' || $column === '' || $rule === '') {
                continue;
            }

            $flags = array_filter(['unique' => (bool) ($row['unique'] ?? false), 'consistent' => (bool) ($row['consistent'] ?? false)]);
            $transformations[$table][$column] = $flags === [] ? $rule : ['type' => $rule, ...$flags];
        }

        return (string) json_encode([
            '$schema' => self::SCHEMA,
            'transformations' => $transformations === [] ? new \stdClass : $transformations,
            'truncate' => array_values(array_unique(array_filter(array_map('trim', $truncate)))),
            'options' => [
                'faker_locale' => (string) ($options['faker_locale'] ?? self::OPTIONS['faker_locale']),
                'password' => (string) ($options['password'] ?? self::OPTIONS['password']),
                'consistent' => (bool) ($options['consistent'] ?? self::OPTIONS['consistent']),
                'gzip' => (bool) ($options['gzip'] ?? self::OPTIONS['gzip']),
                ...array_diff_key($options, self::OPTIONS),
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    }

    /**
     * A start for a Laravel app: the users table and the tables that hold nothing worth keeping.
     *
     * @return array{rules: list<Rule>, truncate: list<string>, options: array<string, mixed>}
     */
    public static function starter(): array
    {
        return self::parse([
            'transformations' => [
                'users' => [
                    'name' => 'fakeName',
                    'email' => ['type' => 'fakeEmail', 'unique' => true, 'consistent' => true],
                    'password' => 'password',
                    'remember_token' => 'null',
                ],
            ],
            'truncate' => ['sessions', 'jobs', 'failed_jobs', 'password_reset_tokens', 'personal_access_tokens'],
        ]);
    }
}
