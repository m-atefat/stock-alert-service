local ids = redis.call('ZRANGEBYSCORE', KEYS[1], ARGV[1], ARGV[2], 'LIMIT', 0, tonumber(ARGV[3]))

if #ids == 0 then
  return {}
end

for i = 1, #ids, 1000 do
  local last = math.min(i + 999, #ids)
  redis.call('ZREM', KEYS[1], unpack(ids, i, last))
end

return ids
