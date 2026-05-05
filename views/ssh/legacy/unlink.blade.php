{{-- Plugin override of the core unlink view. Revokes all privileges +
     GRANT OPTION from every `'user'@'host'` row for this username, so the
     dual `localhost` + `127.0.0.1` pair (and any extra remote rows) all
     lose access in lockstep.

     REVOKE may fail with 1141 "no such grant" if the user already has no
     privileges (matches the core view's pattern: ignored). --}}
HOSTS=$(sudo mysql -BN -e "SELECT Host FROM mysql.user WHERE User='{{ $username }}'")
for H in $HOSTS; do
    sudo mysql -e "REVOKE ALL PRIVILEGES, GRANT OPTION FROM '{{ $username }}'@'$H'" 2>/dev/null || true
done

if ! sudo mysql -e "FLUSH PRIVILEGES"; then
    echo 'VITO_SSH_ERROR' && exit 1
fi

echo "Command executed"
