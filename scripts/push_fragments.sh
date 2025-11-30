#!/bin/bash
# =============================================================================
# Automated fragment creation and distribution
# =============================================================================

# Step 1: Run the fragment creation script (both are in the same folder)
./create_fragments.sh

# Step 2: Define fragment files
STAGING_DIR="/var/www/html/myProject/staging"
NODE1_FILE="$STAGING_DIR/Users_node1.sql"
NODE2_FILE="$STAGING_DIR/Users_node2.sql"

# Step 3: Define receiving nodes
SERVER1_URL="http://ccscloud.dlsu.edu.ph:60230/myProject/receive_fragments.php"
SERVER2_URL="http://ccscloud.dlsu.edu.ph:60231/myProject/receive_fragments.php"

# Step 4: Send fragments to Server1
curl -F "Users_node1.sql=@$NODE1_FILE" -F "Users_node2.sql=@$NODE2_FILE" $SERVER1_URL

# Step 5: Send fragments to Server2
curl -F "Users_node1.sql=@$NODE1_FILE" -F "Users_node2.sql=@$NODE2_FILE" $SERVER2_URL

echo "Fragments distributed to Server1 and Server2 successfully."
