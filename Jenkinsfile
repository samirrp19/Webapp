pipeline {
    agent any

    environment {
        AWS_REGION            = 'us-east-1'
        AWS_ACCOUNT_ID        = '179968400330'

        LOCAL_IMAGE_NAME      = 'php-webapp-image'
        LOCAL_IMAGE_TAG       = 'latest'
        ECR_REPOSITORY        = 'poc/demo'
        ECR_IMAGE_TAG         = "build-${BUILD_NUMBER}"

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

    tools {
        // must match the name configured in Global Tool Configuration
        sonarQube 'SonarScanner'
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
                withSonarQubeEnv('SonarQube') {
                    sh '''
                        set -e
                        ${SONAR_SCANNER_HOME}/bin/sonar-scanner \
                          -Dsonar.projectKey=${SONAR_PROJECT_KEY} \
                          -Dsonar.projectName=${SONAR_PROJECT_NAME} \
                          -Dsonar.sources=${SONAR_SOURCES} \
                          -Dsonar.exclusions=vendor/**,node_modules/**,tmp/**,logs/**
                    '''
                }
            }
        }

        stage('SonarQube Quality Gate') {
            steps {
                timeout(time: 10, unit: 'MINUTES') {
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

        // keep your remaining AWS / ECR / EC2 / SSM stages here
    }
}
