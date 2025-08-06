<?php
require 'vendor/autoload.php';

use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;

$secret = '00a563e20f5b32ce9e85fc801396be97';
$config = Configuration::forSymmetricSigner(new Sha256(), InMemory::plainText($secret));
$token = $config->builder()->withClaim('mercure', ['publish' => ['*']])->getToken($config->signer(), $config->signingKey());
echo $token->toString();
