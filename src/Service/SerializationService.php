<?php

declare(strict_types=1);

namespace KaririCode\Cache\Service;

use KaririCode\Cache\Contract\CacheSerializer;
use KaririCode\Cache\Exception\CacheException;
use KaririCode\Cache\Exception\InvalidArgumentException;
use KaririCode\Cache\Serializer\IgbinarySerializer;
use KaririCode\Cache\Serializer\JsonSerializer;
use KaririCode\Cache\Serializer\NativeSerializer;
use KaririCode\Contract\ImmutableValue;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Gerencia as estratégias de serialização para o cache.
 *
 * Esta classe atua como um hub central, registando e fornecendo diferentes
 * serializadores (ex: nativo do PHP, JSON, Igbinary). Ela permite que a aplicação
 * alterne entre os serializadores de forma flexível.
 *
 * Design Patterns:
 * - Strategy: Permite que diferentes algoritmos (serializadores) sejam selecionados em tempo de execução.
 * - Singleton/Registry: Mantém uma única instância de cada serializador registrado.
 * - Facade: Fornece uma interface simples para um subsistema complexo de serialização.
 */
class SerializationService implements CacheSerializer
{
    /** @var array<string, CacheSerializer> Repositório de serializadores registrados. */
    private array $serializers = [];

    /** @var string O nome do serializador padrão a ser usado. */
    private string $defaultSerializer = 'native';

    /**
     * Construtor do serviço de serialização.
     *
     * @param LoggerInterface $logger uma instância de logger PSR-3 para registrar eventos
     */
    public function __construct(
        private readonly LoggerInterface $logger = new NullLogger()
    ) {
        $this->registerDefaultSerializers();
    }

    /**
     * Registra um novo serializador com um nome específico.
     *
     * @param string $name o nome para identificar o serializador (ex: 'json')
     * @param CacheSerializer $serializer a instância do serializador
     */
    public function registerSerializer(string $name, CacheSerializer $serializer): void
    {
        $this->serializers[$name] = $serializer;
        $this->logger->debug('Serializador registrado', ['name' => $name]);
    }

    /**
     * Serializa um valor usando um serializador específico ou o padrão.
     *
     * @param mixed $value o valor a ser serializado
     * @param string|null $serializerName o nome do serializador a ser usado
     *
     * @throws CacheException se a serialização falhar
     *
     * @return string o valor serializado
     */
    public function serialize(mixed $value, ?string $serializerName = null): string
    {
        $serializer = $this->getSerializer($serializerName);

        try {
            return $serializer->serialize($value);
        } catch (\Throwable $e) {
            $this->logger->error('Falha na serialização', [
                'serializer' => $serializerName ?? $this->defaultSerializer,
                'error' => $e->getMessage(),
            ]);
            // Usa o método de fábrica estático para consistência.
            throw CacheException::serializationFailed(get_debug_type($value), $e);
        }
    }

    /**
     * Desserializa um valor usando um serializador específico ou o padrão.
     *
     * @param string $data o valor serializado
     * @param string|null $serializerName o nome do serializador a ser usado
     *
     * @throws CacheException se a desserialização falhar
     *
     * @return mixed o valor desserializado
     */
    public function unserialize(string $data, ?string $serializerName = null): mixed
    {
        $serializer = $this->getSerializer($serializerName);

        try {
            return $serializer->unserialize($data);
        } catch (\Throwable $e) {
            $this->logger->error('Falha na desserialização', [
                'serializer' => $serializerName ?? $this->defaultSerializer,
                'error' => $e->getMessage(),
            ]);
            // Usa o método de fábrica estático para consistência.
            throw CacheException::deserializationFailed($serializerName ?? 'default', $e);
        }
    }

    /**
     * Define o serializador padrão a ser utilizado.
     *
     * @param string $name o nome do serializador a ser definido como padrão
     *
     * @throws InvalidArgumentException se o serializador não estiver registrado
     */
    public function setDefaultSerializer(string $name): void
    {
        if (!isset($this->serializers[$name])) {
            // Corrigido: Utiliza o método de fábrica estático para criar a exceção.
            throw InvalidArgumentException::invalidSerializer($name);
        }

        $this->defaultSerializer = $name;
    }

    /**
     * Obtém uma instância de serializador pelo nome.
     *
     * @param string|null $name O nome do serializador. Se nulo, retorna o padrão.
     *
     * @throws InvalidArgumentException se o serializador solicitado não for encontrado
     *
     * @return CacheSerializer a instância do serializador
     */
    private function getSerializer(?string $name): CacheSerializer
    {
        $name = $name ?? $this->defaultSerializer;

        if (!isset($this->serializers[$name])) {
            // Corrigido: Utiliza o método de fábrica estático para criar a exceção.
            throw InvalidArgumentException::invalidSerializer($name);
        }

        return $this->serializers[$name];
    }

    /**
     * Registra os serializadores padrão (native, json, igbinary).
     */
    private function registerDefaultSerializers(): void
    {
        $this->serializers['native'] = new NativeSerializer();
        $this->serializers['json'] = new JsonSerializer();

        // Igbinary é opcional, só registra se a extensão estiver disponível.
        if (extension_loaded('igbinary')) {
            $this->serializers['igbinary'] = new IgbinarySerializer();
        }
    }

    // Métodos da interface CacheSerializer delegados para o serializador padrão.

    public function canSerialize(mixed $value, ?string $serializerName = null): bool
    {
        return $this->getSerializer($serializerName)->canSerialize($value);
    }

    public function getMimeType(): string
    {
        return $this->getSerializer(null)->getMimeType();
    }

    public function getConfiguration(): ImmutableValue
    {
        return $this->getSerializer(null)->getConfiguration();
    }
}
