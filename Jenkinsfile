pipeline {
    agent any

    environment {
        AWS_REGION            = 'us-east-1'
        AWS_ACCOUNT_ID        = '179968400330'

        // App / image
        LOCAL_IMAGE_NAME      = 'php-webapp-image'
        LOCAL_IMAGE_TAG       = 'latest'
        ECR_REPOSITORY        = 'poc/demo'
        ECR_IMAGE_TAG         = "build-${BUILD_NUMBER}"

        // Container runtime on EC2
        CONTAINER_NAME        = 'php-webapp-container'
        HOST_PORT             = '8085'
        CONTAINER_PORT        = '80'

        // Infra names
        INSTANCE_PROFILE_NAME = 'EC2-SSM-Profile'
        IAM_ROLE_NAME         = 'EC2-SSM-Role'
        SECURITY_GROUP_NAME   = 'jenkins-ec2-web-sg'
        INSTANCE_NAME         = 'jenkins-php-webapp'
        INSTANCE_TYPE         = 't2.micro'

        // Ubuntu 22.04 LTS official AMI parameter from Canonical
        UBUNTU_SSM_PARAM      = '/aws/service/canonical/ubuntu/server/22.04/stable/current/amd64/hvm/ebs-gp2/ami-id'
    }

    options {
        timestamps()
        disableConcurrentBuilds()
    }

    stages {

        stage('Precheck Tools') {
            steps {
                sh '''
                    set -e
                    docker --version
                    /usr/bin/trivy --version
                    aws --version
                    aws sts get-caller-identity --region ${AWS_REGION}
                '''
            }
        }

        stage('Build Docker Image') {
            steps {
                sh '''
                    set -e
                    docker build -t ${LOCAL_IMAGE_NAME}:${LOCAL_IMAGE_TAG} .
                    docker images | grep ${LOCAL_IMAGE_NAME}
                '''
            }
        }

        stage('Trivy Scan') {
            steps {
                sh '''
                    set -e
                    /usr/bin/trivy image --severity HIGH,CRITICAL --exit-code 1 --no-progress ${LOCAL_IMAGE_NAME}:${LOCAL_IMAGE_TAG}
                '''
            }
        }

        stage('Ensure Default VPC') {
            steps {
                sh '''
                    set -euo pipefail

                    DEFAULT_VPC_ID=$(aws ec2 describe-vpcs \
                      --filters Name=isDefault,Values=true \
                      --region ${AWS_REGION} \
                      --query "Vpcs[0].VpcId" \
                      --output text)

                    if [ "$DEFAULT_VPC_ID" = "None" ] || [ -z "$DEFAULT_VPC_ID" ]; then
                      echo "No default VPC found. Creating one..."
                      aws ec2 create-default-vpc --region ${AWS_REGION} >/tmp/create-default-vpc.json

                      DEFAULT_VPC_ID=$(aws ec2 describe-vpcs \
                        --filters Name=isDefault,Values=true \
                        --region ${AWS_REGION} \
                        --query "Vpcs[0].VpcId" \
                        --output text)
                    else
                      echo "Default VPC already exists: $DEFAULT_VPC_ID"
                    fi

                    echo "DEFAULT_VPC_ID=$DEFAULT_VPC_ID" > infra.env
                '''
                script {
                    def props = readProperties file: 'infra.env'
                    env.DEFAULT_VPC_ID = props.DEFAULT_VPC_ID
                }
            }
        }

        stage('Ensure Default Subnet') {
            steps {
                sh '''
                    set -euo pipefail

                    DEFAULT_SUBNET_ID=$(aws ec2 describe-subnets \
                      --filters Name=vpc-id,Values=${DEFAULT_VPC_ID} Name=default-for-az,Values=true \
                      --region ${AWS_REGION} \
                      --query "Subnets[0].SubnetId" \
                      --output text)

                    if [ "$DEFAULT_SUBNET_ID" = "None" ] || [ -z "$DEFAULT_SUBNET_ID" ]; then
                      echo "No default subnet found in VPC ${DEFAULT_VPC_ID}"
                      exit 1
                    fi

                    echo "DEFAULT_SUBNET_ID=$DEFAULT_SUBNET_ID" >> infra.env
                '''
                script {
                    def props = readProperties file: 'infra.env'
                    env.DEFAULT_SUBNET_ID = props.DEFAULT_SUBNET_ID
                }
            }
        }

        stage('Ensure IAM Role and Instance Profile for SSM') {
            steps {
                sh '''
                    set -euo pipefail

                    cat > trust-policy.json <<'EOF'
                    {
                      "Version": "2012-10-17",
                      "Statement": [
                        {
                          "Effect": "Allow",
                          "Principal": { "Service": "ec2.amazonaws.com" },
                          "Action": "sts:AssumeRole"
                        }
                      ]
                    }
                    EOF

                    ROLE_EXISTS=$(aws iam get-role --role-name ${IAM_ROLE_NAME} --query "Role.RoleName" --output text 2>/dev/null || true)
                    if [ -z "$ROLE_EXISTS" ] || [ "$ROLE_EXISTS" = "None" ]; then
                      echo "Creating IAM role ${IAM_ROLE_NAME}"
                      aws iam create-role \
                        --role-name ${IAM_ROLE_NAME} \
                        --assume-role-policy-document file://trust-policy.json >/tmp/create-role.json
                    else
                      echo "IAM role ${IAM_ROLE_NAME} already exists"
                    fi

                    echo "Attaching AmazonSSMManagedInstanceCore policy"
                    aws iam attach-role-policy \
                      --role-name ${IAM_ROLE_NAME} \
                      --policy-arn arn:aws:iam::aws:policy/AmazonSSMManagedInstanceCore || true

                    PROFILE_EXISTS=$(aws iam get-instance-profile \
                      --instance-profile-name ${INSTANCE_PROFILE_NAME} \
                      --query "InstanceProfile.InstanceProfileName" \
                      --output text 2>/dev/null || true)

                    if [ -z "$PROFILE_EXISTS" ] || [ "$PROFILE_EXISTS" = "None" ]; then
                      echo "Creating instance profile ${INSTANCE_PROFILE_NAME}"
                      aws iam create-instance-profile \
                        --instance-profile-name ${INSTANCE_PROFILE_NAME} >/tmp/create-instance-profile.json
                    else
                      echo "Instance profile ${INSTANCE_PROFILE_NAME} already exists"
                    fi

                    echo "Waiting for IAM propagation..."
                    sleep 15

                    ROLE_ALREADY_IN_PROFILE=$(aws iam get-instance-profile \
                      --instance-profile-name ${INSTANCE_PROFILE_NAME} \
                      --query "InstanceProfile.Roles[?RoleName=='${IAM_ROLE_NAME}'].RoleName | [0]" \
                      --output text 2>/dev/null || true)

                    if [ "$ROLE_ALREADY_IN_PROFILE" != "${IAM_ROLE_NAME}" ]; then
                      echo "Adding role ${IAM_ROLE_NAME} to instance profile ${INSTANCE_PROFILE_NAME}"
                      aws iam add-role-to-instance-profile \
                        --instance-profile-name ${INSTANCE_PROFILE_NAME} \
                        --role-name ${IAM_ROLE_NAME}
                    else
                      echo "Role already present in instance profile"
                    fi
                '''
            }
        }

        stage('Ensure Security Group') {
            steps {
                sh '''
                    set -euo pipefail

                    SG_ID=$(aws ec2 describe-security-groups \
                      --filters Name=vpc-id,Values=${DEFAULT_VPC_ID} Name=group-name,Values=${SECURITY_GROUP_NAME} \
                      --region ${AWS_REGION} \
                      --query "SecurityGroups[0].GroupId" \
                      --output text 2>/dev/null || true)

                    if [ -z "$SG_ID" ] || [ "$SG_ID" = "None" ]; then
                      echo "Creating security group ${SECURITY_GROUP_NAME}"
                      SG_ID=$(aws ec2 create-security-group \
                        --group-name ${SECURITY_GROUP_NAME} \
                        --description "Security group for Jenkins deployed PHP web app" \
                        --vpc-id ${DEFAULT_VPC_ID} \
                        --region ${AWS_REGION} \
                        --query "GroupId" \
                        --output text)

                      echo "Authorizing inbound ports 22, 80, 443, 8085"
                      aws ec2 authorize-security-group-ingress \
                        --group-id $SG_ID \
                        --protocol tcp --port 22 --cidr 0.0.0.0/0 \
                        --region ${AWS_REGION} || true

                      aws ec2 authorize-security-group-ingress \
                        --group-id $SG_ID \
                        --protocol tcp --port 80 --cidr 0.0.0.0/0 \
                        --region ${AWS_REGION} || true

                      aws ec2 authorize-security-group-ingress \
                        --group-id $SG_ID \
                        --protocol tcp --port 443 --cidr 0.0.0.0/0 \
                        --region ${AWS_REGION} || true

                      aws ec2 authorize-security-group-ingress \
                        --group-id $SG_ID \
                        --protocol tcp --port 8085 --cidr 0.0.0.0/0 \
                        --region ${AWS_REGION} || true
                    else
                      echo "Security group already exists: $SG_ID"
                    fi

                    echo "SECURITY_GROUP_ID=$SG_ID" >> infra.env
                '''
                script {
                    def props = readProperties file: 'infra.env'
                    env.SECURITY_GROUP_ID = props.SECURITY_GROUP_ID
                }
            }
        }

        stage('Get Latest Ubuntu AMI') {
            steps {
                sh '''
                    set -euo pipefail

                    AMI_ID=$(aws ssm get-parameter \
                      --name "${UBUNTU_SSM_PARAM}" \
                      --region ${AWS_REGION} \
                      --query "Parameter.Value" \
                      --output text)

                    if [ -z "$AMI_ID" ] || [ "$AMI_ID" = "None" ]; then
                      echo "Failed to resolve Ubuntu AMI from SSM parameter"
                      exit 1
                    fi

                    echo "Resolved Ubuntu AMI: $AMI_ID"
                    echo "AMI_ID=$AMI_ID" >> infra.env
                '''
                script {
                    def props = readProperties file: 'infra.env'
                    env.AMI_ID = props.AMI_ID
                }
            }
        }

        stage('Launch EC2 Instance') {
            steps {
                sh '''
                    set -euo pipefail

                    INSTANCE_ID=$(aws ec2 run-instances \
                      --image-id ${AMI_ID} \
                      --instance-type ${INSTANCE_TYPE} \
                      --iam-instance-profile Name=${INSTANCE_PROFILE_NAME} \
                      --security-group-ids ${SECURITY_GROUP_ID} \
                      --subnet-id ${DEFAULT_SUBNET_ID} \
                      --associate-public-ip-address \
                      --tag-specifications "ResourceType=instance,Tags=[{Key=Name,Value=${INSTANCE_NAME}},{Key=CreatedBy,Value=Jenkins}]" \
                      --region ${AWS_REGION} \
                      --query "Instances[0].InstanceId" \
                      --output text)

                    echo "Launched instance: $INSTANCE_ID"
                    echo "INSTANCE_ID=$INSTANCE_ID" >> infra.env
                '''
                script {
                    def props = readProperties file: 'infra.env'
                    env.INSTANCE_ID = props.INSTANCE_ID
                }
            }
        }

        stage('Wait for EC2 Running') {
            steps {
                sh '''
                    set -euo pipefail
                    aws ec2 wait instance-running --instance-ids ${INSTANCE_ID} --region ${AWS_REGION}
                    aws ec2 wait instance-status-ok --instance-ids ${INSTANCE_ID} --region ${AWS_REGION}

                    PUBLIC_IP=$(aws ec2 describe-instances \
                      --instance-ids ${INSTANCE_ID} \
                      --region ${AWS_REGION} \
                      --query "Reservations[0].Instances[0].PublicIpAddress" \
                      --output text)

                    echo "PUBLIC_IP=$PUBLIC_IP" >> infra.env
                    echo "Instance public IP: $PUBLIC_IP"
                '''
                script {
                    def props = readProperties file: 'infra.env'
                    env.PUBLIC_IP = props.PUBLIC_IP
                }
            }
        }

        stage('Verify SSM Registration') {
            steps {
                sh '''
                    set -euo pipefail

                    echo "Waiting for instance to register in Systems Manager..."
                    FOUND="false"

                    for i in $(seq 1 30); do
                      MANAGED_ID=$(aws ssm describe-instance-information \
                        --region ${AWS_REGION} \
                        --filters "Key=InstanceIds,Values=${INSTANCE_ID}" \
                        --query "InstanceInformationList[0].InstanceId" \
                        --output text 2>/dev/null || true)

                      if [ "$MANAGED_ID" = "${INSTANCE_ID}" ]; then
                        echo "SSM registration successful for ${INSTANCE_ID}"
                        FOUND="true"
                        break
                      fi

                      echo "SSM not ready yet. Attempt $i/30"
                      sleep 10
                    done

                    if [ "$FOUND" != "true" ]; then
                      echo "Instance did not register to SSM in time"
                      exit 1
                    fi
                '''
            }
        }

        stage('Ensure ECR Repository') {
            steps {
                sh '''
                    set -euo pipefail

                    REPO_NAME=$(aws ecr describe-repositories \
                      --repository-names ${ECR_REPOSITORY} \
                      --region ${AWS_REGION} \
                      --query "repositories[0].repositoryName" \
                      --output text 2>/dev/null || true)

                    if [ -z "$REPO_NAME" ] || [ "$REPO_NAME" = "None" ]; then
                      echo "Creating ECR repository ${ECR_REPOSITORY}"
                      aws ecr create-repository \
                        --repository-name ${ECR_REPOSITORY} \
                        --image-scanning-configuration scanOnPush=true \
                        --region ${AWS_REGION} >/tmp/ecr-create.json
                    else
                      echo "ECR repository already exists"
                    fi
                '''
            }
        }

        stage('Push Image to ECR') {
            steps {
                sh '''
                    set -euo pipefail

                    ECR_URI=${AWS_ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com/${ECR_REPOSITORY}

                    aws ecr get-login-password --region ${AWS_REGION} | \
                      docker login --username AWS --password-stdin ${AWS_ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com

                    docker tag ${LOCAL_IMAGE_NAME}:${LOCAL_IMAGE_TAG} ${ECR_URI}:${ECR_IMAGE_TAG}
                    docker push ${ECR_URI}:${ECR_IMAGE_TAG}

                    echo "ECR_URI=$ECR_URI" >> infra.env
                '''
                script {
                    def props = readProperties file: 'infra.env'
                    env.ECR_URI = props.ECR_URI
                }
            }
        }

        stage('Deploy on EC2 via SSM') {
            steps {
                sh '''
                    set -euo pipefail

                    cat > deploy-commands.json <<EOF
{
  "commands": [
    "set -euxo pipefail",
    "sudo apt-get update -y",
    "sudo apt-get install -y ca-certificates curl gnupg lsb-release",
    "sudo install -m 0755 -d /etc/apt/keyrings",
    "curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg",
    "sudo chmod a+r /etc/apt/keyrings/docker.gpg",
    "echo \\"deb [arch=\\\\$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu \\\\\\$(. /etc/os-release && echo \\\\\\$VERSION_CODENAME) stable\\" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null",
    "sudo apt-get update -y",
    "sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin awscli",
    "sudo systemctl enable docker",
    "sudo systemctl start docker",
    "aws ecr get-login-password --region ${AWS_REGION} | sudo docker login --username AWS --password-stdin ${AWS_ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com",
    "sudo docker rm -f ${CONTAINER_NAME} || true",
    "sudo docker pull ${ECR_URI}:${ECR_IMAGE_TAG}",
    "sudo docker run -d --name ${CONTAINER_NAME} -p ${HOST_PORT}:${CONTAINER_PORT} --restart unless-stopped ${ECR_URI}:${ECR_IMAGE_TAG}",
    "sudo docker ps",
    "curl -I http://localhost:${HOST_PORT} || true"
  ]
}
EOF

                    COMMAND_ID=$(aws ssm send-command \
                      --instance-ids ${INSTANCE_ID} \
                      --document-name "AWS-RunShellScript" \
                      --comment "Deploy PHP app container from Jenkins" \
                      --parameters file://deploy-commands.json \
                      --region ${AWS_REGION} \
                      --query "Command.CommandId" \
                      --output text)

                    echo "SSM command id: $COMMAND_ID"

                    for i in $(seq 1 60); do
                      STATUS=$(aws ssm get-command-invocation \
                        --command-id $COMMAND_ID \
                        --instance-id ${INSTANCE_ID} \
                        --region ${AWS_REGION} \
                        --query "Status" \
                        --output text 2>/dev/null || true)

                      echo "Deployment status: $STATUS"

                      if [ "$STATUS" = "Success" ]; then
                        echo "Deployment succeeded"
                        break
                      fi

                      if [ "$STATUS" = "Failed" ] || [ "$STATUS" = "Cancelled" ] || [ "$STATUS" = "TimedOut" ]; then
                        echo "Deployment failed with status: $STATUS"
                        aws ssm get-command-invocation \
                          --command-id $COMMAND_ID \
                          --instance-id ${INSTANCE_ID} \
                          --region ${AWS_REGION}
                        exit 1
                      fi

                      sleep 10
                    done
                '''
            }
        }

        stage('Final Verification') {
            steps {
                sh '''
                    set -euo pipefail

                    echo "Application should be reachable at:"
                    echo "http://${PUBLIC_IP}:${HOST_PORT}"

                    cat > verify-commands.json <<EOF
{
  "commands": [
    "set -euxo pipefail",
    "sudo docker ps",
    "curl -I http://localhost:${HOST_PORT}"
  ]
}
EOF

                    VERIFY_ID=$(aws ssm send-command \
                      --instance-ids ${INSTANCE_ID} \
                      --document-name "AWS-RunShellScript" \
                      --comment "Verify deployed PHP app" \
                      --parameters file://verify-commands.json \
                      --region ${AWS_REGION} \
                      --query "Command.CommandId" \
                      --output text)

                    sleep 10

                    aws ssm get-command-invocation \
                      --command-id $VERIFY_ID \
                      --instance-id ${INSTANCE_ID} \
                      --region ${AWS_REGION}
                '''
            }
        }
    }

    post {
        always {
            echo "Pipeline completed."
            echo "Instance ID: ${env.INSTANCE_ID ?: 'N/A'}"
            echo "Public IP : ${env.PUBLIC_IP ?: 'N/A'}"
        }
        success {
            echo "Deployment successful."
            echo "Open: http://${env.PUBLIC_IP}:${env.HOST_PORT}"
        }
        failure {
            echo "Pipeline failed. Check Jenkins console output."
        }
    }
}
