# Creating a Test EC2 Instance

Steps for spinning up a throwaway Ubuntu EC2 instance to test ShipYard's setup and deployment flows against a real server. Delete the instance after each test run.

## 1. Launch the instance

In the EC2 console, go to Instances, then click "Executar uma instância" (Launch instance).

- **AMI**: Canonical, Ubuntu (latest LTS), amd64
- **Instance type**: t2.micro is enough for a smoke test of setup/deploy flows. Use something larger if you're testing build steps like `composer install` or `npm run build` on the target server itself.

## 2. Key pair

Under "Par de chaves (login)", click "Criar novo par de chaves".

- **Nome**: something identifiable, e.g. `shipyard test <date>`, so it's easy to find and delete later.
- **Tipo de par de chaves**: ED25519 (smaller and faster than RSA, no real downside here).
- **Formato de arquivo de chave privada**: `.pem` (OpenSSH format, works with phpseclib and native SSH on macOS).

Download the file, then lock down its permissions:

```bash
chmod 400 ~/Downloads/<your-key>.pem
```

## 3. Network settings

Under "Configurações de rede", keep "Criar grupo de segurança" and check all three inbound rules:

- **SSH** (port 22) from Anywhere (0.0.0.0/0)
- **HTTPS** (port 443) from Anywhere
- **HTTP** (port 80) from Anywhere (needed for the app and for Let's Encrypt's HTTP01/webroot ACME challenge)

AWS will warn that 0.0.0.0/0 allows all IPs in. That's expected for a throwaway test box.

## 4. Storage

Under "Configurar armazenamento", bump the root volume from the 8 GiB default to 16 to 20 GiB. 8 GiB fills up quickly once PHP/Composer dependencies, Node build output, and (if testing atomic deploys) multiple release copies under `/releases/` are on disk.

## 5. Launch and wait

Click "Executar instância". Then, from the Instances list, wait for "Verificação de status" to finish (goes from "Inicializando" to passing) before trying to connect.

## 6. Confirm SSH access

Open the instance's detail page and copy its public IPv4 address. Test a plain SSH connection before touching ShipYard:

```bash
ssh -i ~/Downloads/<your-key>.pem ubuntu@<public-ip>
```

The user is `ubuntu` for the Canonical Ubuntu AMI.

## 7. Register the server in ShipYard

Once the plain SSH connection works, add the server in ShipYard using:

- the public IP
- username `ubuntu`
- the private key contents from the `.pem` file

`SSHService` will use these credentials for all further operations against the server.

## 8. Clean up

Terminate the instance from the EC2 console when you're done testing, and delete the key pair if you won't reuse it.
