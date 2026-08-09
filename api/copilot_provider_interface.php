<?php
declare(strict_types=1);

/**
 * LLM Provider arayüzü — Risk Engine'den bağımsız, değiştirilebilir provider katmanı.
 */
interface ZineshCopilotProviderInterface
{
    /**
     * @param array{system:string,user:string,full:string,intent:string} $prompt
     * @param array<string,mixed> $context
     * @return array{provider:string,text:string,sources:array<string,mixed>}
     */
    public function complete(array $prompt, array $context): array;

    public function providerId(): string;
}
