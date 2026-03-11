pipeline {
    agent any

    environment {
        APP_NAME = "php-webapp"
        IMAGE_NAME = "php-webapp-image"
        CONTAINER_NAME = "php-webapp-container"
        HOST_PORT = "8085"
        CONTAINER_PORT = "80"
        GIT_REPO = "git@github.com:samirrp19/Webapp.git"
        BRANCH = "master"
    }

    options {
        timestamps()
        disableConcurrentBuilds()
    }

    triggers {
        githubPush()
    }

    stages {

        stage('Clean Workspace') {
            steps {
                cleanWs()
            }
        }

        stage('Checkout Source Code') {
            steps {
                git branch: "${BRANCH}",
                    credentialsId: 'github-token',
                    url: "${GIT_REPO}"
            }
        }

        stage('Verify Files') {
            steps {
                sh '''
                echo "Checking project files"
                pwd
                ls -ltr
                '''
            }
        }

        stage('Build Docker Image') {
            steps {
                sh '''
                echo "Building Docker image..."
                docker build -t ${IMAGE_NAME}:${BUILD_NUMBER} .
                docker tag ${IMAGE_NAME}:${BUILD_NUMBER} ${IMAGE_NAME}:latest
                '''
            }
        }

        stage('Stop Previous Container') {
            steps {
                sh '''
                echo "Stopping old container if exists..."
                docker rm -f ${CONTAINER_NAME} || true
                '''
            }
        }

        stage('Deploy New Container') {
            steps {
                sh '''
                echo "Starting new container..."
                docker run -d \
                --name ${CONTAINER_NAME} \
                -p ${HOST_PORT}:${CONTAINER_PORT} \
                ${IMAGE_NAME}:latest
                '''
            }
        }

        stage('Verify Deployment') {
            steps {
                sh '''
                echo "Checking running containers"
                docker ps

                echo "Testing application"
                sleep 10
                curl -I http://localhost:${HOST_PORT} || true
                '''
            }
        }

    }

    post {

        success {
            echo "Deployment successful"
            echo "Application available at:"
            echo "http://<JENKINS_SERVER_IP>:${HOST_PORT}"
        }

        failure {
            echo "Pipeline failed"
        }

        always {
            sh 'docker images | head -20'
        }

    }
}
