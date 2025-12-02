#!/bin/bash
# =============================================================================
# Script: deploy_recovery_schema.sh
# Purpose: Deploy recovery schema to all nodes in the distributed database
# Run this script from the Central Node (10.2.14.129)
# =============================================================================

USER="G9_1"
PASS="pass1234"
DB="faker"
SCHEMA_FILE="/var/www/html/myProject/staging/recovery_schema.sql"

# Node IPs (matching your RecoveryManager.php configuration)
CENTRAL="10.2.14.129"
NODE2="10.2.14.130"
NODE3="10.2.14.131"

# SSH user for remote nodes
SSH_USER="simon"

echo "=== Deploying Recovery Schema to All Nodes ==="

# Run on Central (local)
echo "Applying schema to Central Node ($CENTRAL)..."
mysql -u $USER -p$PASS $DB < "$SCHEMA_FILE" && echo "✔ Central node schema applied" || echo "✖ Failed on Central"

# Push and run on Node2
echo "Applying schema to Node2 ($NODE2)..."
ssh $SSH_USER@$NODE2 "mysql -u $USER -p$PASS $DB" < "$SCHEMA_FILE" && echo "✔ Node2 schema applied" || echo "✖ Failed on Node2"

# Push and run on Node3
echo "Applying schema to Node3 ($NODE3)..."
ssh $SSH_USER@$NODE3 "mysql -u $USER -p$PASS $DB" < "$SCHEMA_FILE" && echo "✔ Node3 schema applied" || echo "✖ Failed on Node3"

echo ""
echo "=== Schema Deployment Complete ==="