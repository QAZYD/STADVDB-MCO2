#!/bin/bash
# distribute_fragments.sh
# Purpose: Copy SQL fragments from Node0 to Node1 and Node2

# Paths on local (Node0)
LOCAL_PROJECT="/var/www/html/myProject"
NODE1_SQL="$LOCAL_PROJECT/Users_node1.sql"
NODE2_SQL="$LOCAL_PROJECT/Users_node2.sql"

# Remote credentials and ports
NODE1_HOST="ccscloud.dlsu.edu.ph"
NODE1_SSH_PORT=60530

NODE2_HOST="ccscloud.dlsu.edu.ph"
NODE2_SSH_PORT=60531

REMOTE_PROJECT="/var/www/html/myProject"

# Ensure Node1 and Node2 folders exist on remote
ssh -p $NODE1_SSH_PORT root@$NODE1_HOST "mkdir -p $REMOTE_PROJECT/Node1"
ssh -p $NODE2_SSH_PORT root@$NODE2_HOST "mkdir -p $REMOTE_PROJECT/Node2"

# Copy files
scp -P $NODE1_SSH_PORT "$NODE1_SQL" root@$NODE1_HOST:$REMOTE_PROJECT/Node1/
scp -P $NODE2_SSH_PORT "$NODE2_SQL" root@$NODE2_HOST:$REMOTE_PROJECT/Node2/

echo "Fragments copied to Node1 and Node2 folders successfully."
