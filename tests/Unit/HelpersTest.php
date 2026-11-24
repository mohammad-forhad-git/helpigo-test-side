<?php

declare(strict_types=1);

use App\Core\Validator;

test('Bangladeshi mobile numbers are normalised to 11 digits', function () {
    assert_same('01712345678', Validator::normalizeBdPhone('01712345678'));
    assert_same('01712345678', Validator::normalizeBdPhone('+880 1712-345678'));
    assert_same('01912345678', Validator::normalizeBdPhone('8801912345678'));
    assert_same(null, Validator::normalizeBdPhone('01212345678'), 'operator prefix 012 does not exist');
    assert_same(null, Validator::normalizeBdPhone('0171234567'), 'too short');
});

test('phone numbers are masked for people who should not see them', function () {
    assert_same('017XX-XXX678', mask_phone('01712345678'));
    assert_same('', mask_phone(null));
});

test('t() returns English text with placeholders filled', function () {
    assert_same('Hi Rahim', t('Hi :name', ['name' => 'Rahim']));
});
