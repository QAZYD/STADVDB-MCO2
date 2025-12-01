#!/bin/bash

# FULL PATH instead of relative
/var/www/html/myProject/scripts/create_fragments.sh

STAGING_DIR="/var/www/html/myProject/staging"
NODE1_FILE="$STAGING_DIR/Users_node1.sql"
NODE2_FILE="$STAGING_DIR/Users_node2.sql"

# Server1 (internal)
scp $NODE1_FILE root@10.2.14.130:/var/www/html/myProject/Node1/
scp $NODE2_FILE root@10.2.14.130:/var/www/html/myProject/Node2/

# Server2 (internal)
scp $NODE1_FILE root@10.2.14.131:/var/www/html/myProject/Node1/
scp $NODE2_FILE root@10.2.14.131:/var/www/html/myProject/Node2/

echo "Fragments distributed to Server1 and Server2 successfully."
