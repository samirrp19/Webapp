pipeline {
    agent any

    environment {
        AWS_REGION            = 'us-east-1'
        AWS_ACCOUNT_ID        = '179968400330'

        LOCAL_IMAGE_NAME      = 'php-webapp-image'
        LOCAL_IMAGE_TAG       = 'latest'

        ECR_REPOSITORY        = 'webapp-demo'
        ECR_IMAGE_TAG         = "build-${BUILD_NUMBER}"
        ECR_REGISTRY          = "${AWS_ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com"
        ECR_IMAGE_URI         = "${AWS_ACCOUNT_ID}.dkr.ecr.${AWS_REGION}.amazonaws.com/${ECR_REPOSITORY}:${ECR_IMAGE_TAG}"

        CONTAINER_NAME        = 'php-webapp-container'
        HOST_PORT             = '8085'
        CONTAINER_PORT        = '80'

        INSTANCE_PROFILE_NAME = 'EC2-SSM-Profile'
        INSTANCE_NAME         = 'jenkins-php-webapp'
        INSTANCE_TYPE         = 't2.micro'

        KEY_PAIR_NAME         = 'samir-demo-ec2-key'

        UBUNTU_SSM_PARAM      = '/aws/service/canonical/ubuntu/server/22.04/stable/current/amd64/hvm/ebs-gp2/ami-id'

        SONAR_PROJECT_KEY     = 'php-webapp'
        SONAR_PROJECT_NAME    = 'php-webapp'
        SONAR_SOURCES         = '.'

        VPC_ID                = 'vpc-0bbcd6bfd0a21ce09'
        SUBNET_ID             = 'subnet-0d2d7bcd16a9ef6c3'
        SECURITY_GROUP_ID     = 'sg-0ae95a03ba22b71b8'
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

        stage('Validate EC2 Key Pair') {
            steps {
                sh '''
                    set -e
                    aws ec2 describe-key-pairs \
                      --key-names "${KEY_PAIR_NAME}" \
                      --region "${AWS_REGION}" \
                      --query "KeyPairs[0].KeyName" \
                      --output text
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

        stage('Resolve AMI') {
            steps {
                script {
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

                    if (!env.AMI_ID || env.AMI_ID == 'None') {
                        error("Could not resolve Ubuntu AMI from SSM parameter")
                    }

                    echo "Using VPC_ID=${env.VPC_ID}"
                    echo "Using SUBNET_ID=${env.SUBNET_ID}"
                    echo "Using SECURITY_GROUP_ID=${env.SECURITY_GROUP_ID}"
                    echo "Using KEY_PAIR_NAME=${env.KEY_PAIR_NAME}"
                    echo "Using AMI_ID=${env.AMI_ID}"
                }
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
                              --key-name "${KEY_PAIR_NAME}" \
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

        stage('Install Docker and AWS CLI on EC2 Through SSM') {
            steps {
                script {
                    env.SSM_SETUP_COMMAND_ID = sh(
                        script: '''
                            aws ssm send-command \
                              --region "${AWS_REGION}" \
                              --instance-ids "${INSTANCE_ID}" \
                              --document-name "AWS-RunShellScript" \
                              --comment "Install Docker and AWS CLI on Ubuntu EC2" \
                              --parameters 'commands=[
                                "set -euxo pipefail",
                                "sudo apt-get update -y",
                                "sudo apt-get install -y docker.io unzip curl",
                                "curl \\"https://awscli.amazonaws.com/awscli-exe-linux-x86_64.zip\\" -o \\"awscliv2.zip\\"",
                                "unzip -o awscliv2.zip",
                                "sudo ./aws/install --update",
                                "sudo systemctl enable docker",
                                "sudo systemctl start docker",
                                "sudo usermod -aG docker ubuntu || true",
                                "docker --version",
                                "aws --version"
                              ]' \
                              --query "Command.CommandId" \
                              --output text
                        ''',
                        returnStdout: true
                    ).trim()

                    echo "SSM_SETUP_COMMAND_ID=${env.SSM_SETUP_COMMAND_ID}"
                }

                timeout(time: 10, unit: 'MINUTES') {
                    waitUntil {
                        script {
                            def currentStatus = sh(
                                script: '''
                                    aws ssm get-command-invocation \
                                      --region "${AWS_REGION}" \
                                      --command-id "${SSM_SETUP_COMMAND_ID}" \
                                      --instance-id "${INSTANCE_ID}" \
                                      --query "Status" \
                                      --output text
                                ''',
                                returnStdout: true
                            ).trim()

                            echo "Setup SSM status: ${currentStatus}"
                            return ["Success", "Failed", "Cancelled", "TimedOut"].contains(currentStatus)
                        }
                    }
                }

                sh '''
                    aws ssm get-command-invocation \
                      --region "${AWS_REGION}" \
                      --command-id "${SSM_SETUP_COMMAND_ID}" \
                      --instance-id "${INSTANCE_ID}" \
                      --query "[Status,StandardOutputContent,StandardErrorContent]" \
                      --output text
                '''

                script {
                    def finalStatus = sh(
                        script: '''
                            aws ssm get-command-invocation \
                              --region "${AWS_REGION}" \
                              --command-id "${SSM_SETUP_COMMAND_ID}" \
                              --instance-id "${INSTANCE_ID}" \
                              --query "Status" \
                              --output text
                        ''',
                        returnStdout: true
                    ).trim()

                    if (finalStatus != 'Success') {
                        error("EC2 setup failed. Check StandardErrorContent above.")
                    }
                }
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
                                "set -euxo pipefail",
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

                timeout(time: 10, unit: 'MINUTES') {
                    waitUntil {
                        script {
                            def currentStatus = sh(
                                script: '''
                                    aws ssm get-command-invocation \
                                      --region "${AWS_REGION}" \
                                      --command-id "${SSM_DEPLOY_COMMAND_ID}" \
                                      --instance-id "${INSTANCE_ID}" \
                                      --query "Status" \
                                      --output text
                                ''',
                                returnStdout: true
                            ).trim()

                            echo "Deploy SSM status: ${currentStatus}"
                            return ["Success", "Failed", "Cancelled", "TimedOut"].contains(currentStatus)
                        }
                    }
                }

                sh '''
                    aws ssm get-command-invocation \
                      --region "${AWS_REGION}" \
                      --command-id "${SSM_DEPLOY_COMMAND_ID}" \
                      --instance-id "${INSTANCE_ID}" \
                      --query "[Status,StandardOutputContent,StandardErrorContent]" \
                      --output text
                '''

                script {
                    def finalStatus = sh(
                        script: '''
                            aws ssm get-command-invocation \
                              --region "${AWS_REGION}" \
                              --command-id "${SSM_DEPLOY_COMMAND_ID}" \
                              --instance-id "${INSTANCE_ID}" \
                              --query "Status" \
                              --output text
                        ''',
                        returnStdout: true
                    ).trim()

                    if (finalStatus != 'Success') {
                        error("Container deployment failed via SSM. Check StandardErrorContent above.")
                    }
                }
            }
        }

        stage('Show Access Info') {
            steps {
                echo "Application should be reachable at: http://${env.INSTANCE_PUBLIC_IP}:${env.HOST_PORT}"
                echo "SSH command: ssh -i samir-demo-ec2-key.pem ubuntu@${env.INSTANCE_PUBLIC_IP}"
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
            echo "SSH: ssh -i samir-demo-ec2-key.pem ubuntu@${env.INSTANCE_PUBLIC_IP}"
        }
        failure {
            echo "Pipeline failed. Check stage logs."
        }
        aborted {
            echo "Pipeline aborted."
        }
    }
}
