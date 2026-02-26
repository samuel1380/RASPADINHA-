-- ============================================================
-- Script para limpar transações duplicadas no banco de dados
-- Execute este script UMA VEZ no phpMyAdmin ou terminal MariaDB
-- ============================================================

-- PASSO 1: Ver quantos duplicados existem antes de limpar
SELECT 
    user_id,
    type,
    status,
    JSON_UNQUOTE(JSON_EXTRACT(details, '$.txId')) as txId,
    COUNT(*) as total,
    GROUP_CONCAT(id ORDER BY id ASC) as ids
FROM transactions 
WHERE type IN ('DEPOSIT', 'WITHDRAW')
  AND status = 'PENDING'
GROUP BY user_id, type, JSON_EXTRACT(details, '$.txId')
HAVING COUNT(*) > 1;

-- PASSO 2: Remover PENDING duplicados - manter apenas o de menor ID (o primeiro)
-- Para DEPÓSITOs duplicados PENDING (mantém o de menor ID):
DELETE t1 FROM transactions t1
INNER JOIN transactions t2 
    ON t1.user_id = t2.user_id 
    AND t1.type = t2.type 
    AND t1.type = 'DEPOSIT'
    AND t1.status = t2.status 
    AND t1.status = 'PENDING'
    AND JSON_EXTRACT(t1.details, '$.txId') = JSON_EXTRACT(t2.details, '$.txId')
    AND JSON_EXTRACT(t1.details, '$.txId') IS NOT NULL
    AND t1.id > t2.id;

-- PASSO 3: Remover COMPLETED duplicados de depósito com mesmo txId
DELETE t1 FROM transactions t1
INNER JOIN transactions t2 
    ON t1.user_id = t2.user_id 
    AND t1.type = t2.type 
    AND t1.type = 'DEPOSIT'
    AND t1.status = t2.status 
    AND t1.status = 'COMPLETED'
    AND JSON_EXTRACT(t1.details, '$.txId') = JSON_EXTRACT(t2.details, '$.txId')
    AND JSON_EXTRACT(t1.details, '$.txId') IS NOT NULL
    AND t1.id > t2.id;

-- PASSO 4: Verificar resultado final
SELECT 
    type, 
    status, 
    COUNT(*) as total 
FROM transactions 
GROUP BY type, status 
ORDER BY type, status;

-- FIM DO SCRIPT
