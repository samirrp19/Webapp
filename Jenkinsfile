pipeline {
    agent any

    environment {
        AWS_REGION            = 'us-east-1'
        AWS_ACCOUNT_ID        = '179968400330'

        LOCAL_IMAGE_NAME      = 'php-webapp-image'
        LOCAL_IMAGE_TAG       = 'latest'
        ECR_REPOSITORY        = 'poc/demo'
        ECR_IMAGE_TAG         = "build-${BUILD_NUMBER}"
        ECR_REGISTRY          = "${AWS_ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com"
        ECR_IMAGE_URI         = "${AWS_ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com/${ECR_REPOSITORY}:${ECR_IMAGE_TAG}"

        CONTAINER_NAME        = 'php-webapp-container'
        HOST_PORT             = '8085'
        CONTAINER_PORT        = '80'

        INSTANCE_PROFILE_NAME = 'EC2-SSM-Profile'
        IAM_ROLE_NAME         = 'EC2-SSM-Role'
        SECURITY_GROUP_NAME   = 'jenkins-ec2-web-sg'
        INSTANCE_NAME         = 'jenkins-php-webapp'
        INSTANCE_TYPE         = 't2.micro'

        UBUNTU_SSM_PARAM      = '/aws/service/canonical/ubuntu/server/22.04/stable/current/amd64/hvm/ebs-gp2/ami-id'

        SONAR_PROJECT_KEY     = 'php-webapp'
        SONAR_PROJECT_NAME    = 'php-webapp'
        SONAR_SOURCES         = '.'
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

        stage('SonarQube Code Scan') {
            steps {
                script {
                    def scannerHome = tool 'SonarScanner'

                    withSonarQubeEnv('SonarQube') {
                        sh """
                            set -e
                            ${scannerHome}/bin/sonar-scanner \
                              -Dsonar.projectKey=${SONAR_PROJECT_KEY} \
                              -Dsonar.projectName=${SONAR_PROJECT_NAME} \
                              -Dsonar.sources=${SONAR_SOURCES} \
                              -Dsonar.exclusions=vendor/**,node_modules/**,tmp/**,logs/**,cache/**,storage/**,dist/**,build/**
                        """
                    }
                }
            }
        }

        stage('SonarQube Quality Gate') {
            steps {
                timeout(time: 15, unit: 'MINUTES') {
                    waitForQualityGate abortPipeline: true
                }
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
                    /usr/bin/trivy image --severity CRITICAL --ignore-unfixed --exit-code 1 --no-progress ${LOCAL_IMAGE_NAME}:${LOCAL_IMAGE_TAG}
                '''
            }
        }

        stage('Ensure ECR Repository Exists') {
            steps {
                sh '''
                    set -e

                    aws ecr describe-repositories \
                      --repository-names "${ECR_REPOSITORY}" \
                      --region "${AWS_REGION}" >/dev/null 2>&1 || \
                    aws ecr create-repository \
                      --repository-name "${ECR_REPOSITORY}" \
                      --image-scanning-configuration scanOnPush=true \
                      --region "${AWS_REGION}"

                    aws ecr describe-repositories \
                      --repository-names "${ECR_REPOSITORY}" \
                      --region "${AWS_REGION}"
                '''
            }
        }

        stage('Login to ECR') {
            steps {
                sh '''
                    set -e
                    aws ecr get-login-password --region "${AWS_REGION}" | \
                    docker login --username AWS --password-stdin "${ECR_REGISTRY}"
                '''
            }
        }

        stage('Tag and Push Image to ECR') {
            steps {
                sh '''
                    set -e
                    docker tag "${LOCAL_IMAGE_NAME}:${LOCAL_IMAGE_TAG}" "${ECR_IMAGE_URI}"
                    docker push "${ECR_IMAGE_URI}"
                '''
            }
        }

        stage('Prepare Network and AMI') {
            steps {
                script {
                    env.VPC_ID = sh(
                        script: '''
                            aws ec2 describe-vpcs \
                              --filters "Name=isDefault,Values=true" \
                              --region "${AWS_REGION}" \
                              --query "Vpcs[0].VpcId" \
                              --output text
                        ''',
                        returnStdout: true
                    ).trim()

                    env.SUBNET_ID = sh(
                        script: '''
                            aws ec2 describe-subnets \
                              --filters "Name=vpc-id,Values=${VPC_ID}" "Name=default-for-az,Values=true" \
                              --region "${AWS_REGION}" \
                              --query "Subnets[0].SubnetId" \
                              --output text
                        ''',
                        returnStdout: true
                    ).trim()

                    env.AMI_ID = sh(
                        script: '''
                            aws ssm get-parameter \
                              --name "${UBUNTU_SSM_PARAM}" \
                              --region "${AWS_REGION}" \
                              --query "Parameter.Value" \
                              --output text
                        ''',
                        returnStdout: true
                    ).trim()

                    echo "VPC_ID=${env.VPC_ID}"
                    echo "SUBNET_ID=${env.SUBNET_ID}"
                    echo "AMI_ID=${env.AMI_ID}"
                }
            }
        }

        stage('Create or Reuse Security Group') {
            steps {
                script {
                    env.SECURITY_GROUP_ID = sh(
                        script: '''
                            SG_ID=$(aws ec2 describe-security-groups \
                              --filters "Name=group-name,Values=${SECURITY_GROUP_NAME}" "Name=vpc-id,Values=${VPC_ID}" \
                              --region "${AWS_REGION}" \
                              --query "SecurityGroups[0].GroupId" \
                              --output text)

                            if [ "$SG_ID" = "None" ] || [ -z "$SG_ID" ]; then
                              SG_ID=$(aws ec2 create-security-group \
                                --group-name "${SECURITY_GROUP_NAME}" \
                                --description "Security group for Jenkins deployed PHP webapp" \
                                --vpc-id "${VPC_ID}" \
                                --region "${AWS_REGION}" \
                                --query "GroupId" \
                                --output text)
                            fi

                            echo "$SG_ID"
                        ''',
                        returnStdout: true
                    ).trim()

                    echo "SECURITY_GROUP_ID=${env.SECURITY_GROUP_ID}"
                }
            }
        }

        stage('Authorize Security Group Rules') {
            steps {
                sh '''
                    set +e

                    aws ec2 authorize-security-group-ingress \
                      --group-id "${SECURITY_GROUP_ID}" \
                      --protocol tcp \
                      --port 22 \
                      --cidr 0.0.0.0/0 \
                      --region "${AWS_REGION}"

                    aws ec2 authorize-security-group-ingress \
                      --group-id "${SECURITY_GROUP_ID}" \
                      --protocol tcp \
                      --port "${HOST_PORT}" \
                      --cidr 0.0.0.0/0 \
                      --region "${AWS_REGION}"

                    aws ec2 authorize-security-group-ingress \
                      --group-id "${SECURITY_GROUP_ID}" \
                      --protocol tcp \
                      --port 80 \
                      --cidr 0.0.0.0/0 \
                      --region "${AWS_REGION}"

                    aws ec2 authorize-security-group-ingress \
                      --group-id "${SECURITY_GROUP_ID}" \
                      --protocol tcp \
                      --port 443 \
                      --cidr 0.0.0.0/0 \
                      --region "${AWS_REGION}"

                    true
                '''
            }
        }

        stage('Launch EC2 Instance') {
            steps {
                script {
                    env.INSTANCE_ID = sh(
                        script: '''
                            aws ec2 run-instances \
                              --image-id "${AMI_ID}" \
                              --instance-type "${INSTANCE_TYPE}" \
                              --iam-instance-profile Name="${INSTANCE_PROFILE_NAME}" \
                              --security-group-ids "${SECURITY_GROUP_ID}" \
                              --subnet-id "${SUBNET_ID}" \
                              --associate-public-ip-address \
                              --tag-specifications "ResourceType=instance,Tags=[{Key=Name,Value=${INSTANCE_NAME}}]" \
                              --region "${AWS_REGION}" \
                              --query "Instances[0].InstanceId" \
                              --output text
                        ''',
                        returnStdout: true
                    ).trim()

                    echo "INSTANCE_ID=${env.INSTANCE_ID}"
                }
            }
        }

        stage('Wait for EC2 Running') {
            steps {
                sh '''
                    set -e
                    aws ec2 wait instance-running \
                      --instance-ids "${INSTANCE_ID}" \
                      --region "${AWS_REGION}"
                '''
                script {
                    env.INSTANCE_PUBLIC_IP = sh(
                        script: '''
                            aws ec2 describe-instances \
                              --instance-ids "${INSTANCE_ID}" \
                              --region "${AWS_REGION}" \
                              --query "Reservations[0].Instances[0].PublicIpAddress" \
                              --output text
                        ''',
                        returnStdout: true
                    ).trim()

                    echo "INSTANCE_PUBLIC_IP=${env.INSTANCE_PUBLIC_IP}"
                }
            }
        }

        stage('Wait for SSM Online') {
            steps {
                sh '''
                    set -e
                    for i in $(seq 1 40); do
                      STATUS=$(aws ssm describe-instance-information \
                        --region "${AWS_REGION}" \
                        --filters "Key=InstanceIds,Values=${INSTANCE_ID}" \
                        --query "InstanceInformationList[0].PingStatus" \
                        --output text 2>/dev/null || true)

                      echo "SSM status: ${STATUS}"

                      if [ "${STATUS}" = "Online" ]; then
                        exit 0
                      fi

                      sleep 15
                    done

                    echo "SSM agent did not become online in time"
                    exit 1
                '''
            }
        }

        stage('Install Docker on EC2 Through SSM') {
            steps {
                script {
                    env.SSM_DOCKER_COMMAND_ID = sh(
                        script: '''
                            aws ssm send-command \
                              --region "${AWS_REGION}" \
                              --instance-ids "${INSTANCE_ID}" \
                              --document-name "AWS-RunShellScript" \
                              --comment "Install Docker on Ubuntu EC2" \
                              --parameters 'commands=[
                                "set -e",
                                "sudo apt-get update -y",
                                "sudo apt-get install -y ca-certificates curl gnupg lsb-release",
                                "sudo install -m 0755 -d /etc/apt/keyrings",
                                "curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg",
                                "sudo chmod a+r /etc/apt/keyrings/docker.gpg",
                                "echo \\"deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo $VERSION_CODENAME) stable\\" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null",
                                "sudo apt-get update -y",
                                "sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin",
                                "sudo systemctl enable docker",
                                "sudo systemctl start docker",
                                "sudo usermod -aG docker ubuntu || true",
                                "sudo docker --version"
                              ]' \
                              --query "Command.CommandId" \
                              --output text
                        ''',
                        returnStdout: true
                    ).trim()

                    echo "SSM_DOCKER_COMMAND_ID=${env.SSM_DOCKER_COMMAND_ID}"
                }

                sh '''
                    set -e
                    aws ssm wait command-executed \
                      --region "${AWS_REGION}" \
                      --command-id "${SSM_DOCKER_COMMAND_ID}" \
                      --instance-id "${INSTANCE_ID}"
                '''

                sh '''
                    aws ssm get-command-invocation \
                      --region "${AWS_REGION}" \
                      --command-id "${SSM_DOCKER_COMMAND_ID}" \
                      --instance-id "${INSTANCE_ID}" \
                      --query "[Status,StandardOutputContent,StandardErrorContent]" \
                      --output text
                '''
            }
        }

        stage('Deploy Container on EC2 Through SSM') {
            steps {
                script {
                    env.SSM_DEPLOY_COMMAND_ID = sh(
                        script: '''
                            aws ssm send-command \
                              --region "${AWS_REGION}" \
                              --instance-ids "${INSTANCE_ID}" \
                              --document-name "AWS-RunShellScript" \
                              --comment "Login to ECR pull image and run container" \
                              --parameters 'commands=[
                                "set -e",
                                "aws ecr get-login-password --region '"${AWS_REGION}"' | sudo docker login --username AWS --password-stdin '"${ECR_REGISTRY}"'",
                                "sudo docker rm -f '"${CONTAINER_NAME}"' || true",
                                "sudo docker pull '"${ECR_IMAGE_URI}"'",
                                "sudo docker run -d --name '"${CONTAINER_NAME}"' -p '"${HOST_PORT}"':'"${CONTAINER_PORT}"' --restart unless-stopped '"${ECR_IMAGE_URI}"'",
                                "sudo docker ps"
                              ]' \
                              --query "Command.CommandId" \
                              --output text
                        ''',
                        returnStdout: true
                    ).trim()

                    echo "SSM_DEPLOY_COMMAND_ID=${env.SSM_DEPLOY_COMMAND_ID}"
                }

                sh '''
                    set -e
                    aws ssm wait command-executed \
                      --region "${AWS_REGION}" \
                      --command-id "${SSM_DEPLOY_COMMAND_ID}" \
                      --instance-id "${INSTANCE_ID}"
                '''

                sh '''
                    aws ssm get-command-invocation \
                      --region "${AWS_REGION}" \
                      --command-id "${SSM_DEPLOY_COMMAND_ID}" \
                      --instance-id "${INSTANCE_ID}" \
                      --query "[Status,StandardOutputContent,StandardErrorContent]" \
                      --output text
                '''
            }
        }

        stage('Show Application URL') {
            steps {
                echo "Application should be reachable at: http://${env.INSTANCE_PUBLIC_IP}:${env.HOST_PORT}"
            }
        }
    }

    post {
        always {
            echo "Build finished with status: ${currentBuild.currentResult}"
        }
        success {
            echo "Deployment completed successfully."
            echo "ECR image: ${env.ECR_IMAGE_URI}"
            echo "EC2 instance: ${env.INSTANCE_ID}"
            echo "App URL: http://${env.INSTANCE_PUBLIC_IP}:${env.HOST_PORT}"
        }
        failure {
            echo "Pipeline failed. Check stage logs."
        }
        aborted {
            echo "Pipeline aborted."
        }
    }
}
