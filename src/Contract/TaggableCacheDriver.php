<?php

declare(strict_types=1);

namespace KaririCode\Cache\Contract;

/**
 * Interface TaggableCacheDriver.
 *
 * Estende o CacheDriver básico para adicionar a capacidade de invalidação por tags.
 * Drivers que implementam esta interface sinalizam ao CacheManager que podem lidar
 * nativamente com a lógica de associação de chaves a tags. Isso permite que o componente
 * delegue a operação de invalidação diretamente ao backend (ex: usando Sets no Redis),
 * o que é muito mais eficiente do que gerenciar as tags na camada da aplicação.
 *
 * @category  Cache
 *
 * @author    Walmir Silva <walmir.silva@kariricode.org>
 * @license   MIT
 *
 * @see       https://kariricode.org/
 */
interface TaggableCacheDriver extends CacheDriver
{
    /**
     * Invalida todos os itens de cache associados a uma ou mais tags especificadas.
     * Esta operação deve ser atômica quando possível no backend.
     *
     * @param array $tags as tags a serem invalidadas
     *
     * @return bool true em caso de sucesso, false em caso de falha
     */
    public function invalidateTags(array $tags): bool;
}
